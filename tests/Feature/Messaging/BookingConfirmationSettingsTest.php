<?php

use App\Enums\UserRole;
use App\Mail\BookingConfirmationMail;
use App\Messaging\BookingConfirmation;
use App\Messaging\BookingConfirmationTexts;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Owner's task of November 25: every new booking confirms itself to the customer by SMS and email with the
 * cancellation policy; Settings → Company → Booking confirmation edits every text, the hours and the fee.
 */
beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/2010-04-01/Accounts/ACsub/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));

    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'country' => 'CA', 'timezone' => 'America/Vancouver', 'currency' => 'CAD', 'locale' => 'en-CA']);
    $this->owner = memberOf($this->company, UserRole::Owner, ['phone' => '+16045550199', 'email' => 'owner@example.com']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance', 'phone' => '+16047398024']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->withEmail('payer@example.com')
        ->create(['first_name' => 'Paula', 'last_name' => 'Payer']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '500 Rental Ave', 'city' => 'Surrey']);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
    $this->actingAs($this->owner);

    $this->book = fn (array $extra = []) => $this->post(route('jobs.store'), [
        'quick_booking' => true, 'brand_id' => $this->brand->id, 'job_type' => 'repair',
        'new_customer_mode' => false, 'customer_id' => $this->customer->id, 'property_id' => $this->property->id,
        'add_visit' => true,
        'visit' => ['date' => '2026-10-16', 'start_time' => '13:00', 'end_time' => '15:00', 'assignee_ids' => [$this->owner->id]],
        ...$extra,
    ]);
    $this->latest = fn () => inCompany($this->company, fn () => ServiceJob::query()->latest('id')->first());
    $this->sms = fn () => inCompany($this->company, fn () => Message::query()->where('channel', Message::SMS)->where('kind', 'booking_confirmation')->latest('id')->value('body'));
});

test('a new booking texts and emails the confirmation with the 24-hour, $85 cancellation policy', function () {
    ($this->book)()->assertSessionHasNoErrors();

    $sms = ($this->sms)();
    $address = inCompany($this->company, fn () => $this->property->fresh()->fullAddress());
    // No appliance on the job: "for … service" is left out.
    expect($sms)->toBe("Hi Paula, this is Doctor Appliance. Your appointment is confirmed for Friday, October 16, 1:00 p.m. - 3:00 p.m. at {$address}. "
        .'We require 24 hours cancellation notice to avoid an $85 service call charge. Reply to this message or call (604) 739-8024 if you need to make any changes.');
    Mail::assertQueued(BookingConfirmationMail::class, fn (BookingConfirmationMail $mail) => $mail->hasTo('payer@example.com')
        && $mail->subjectLine === 'Appointment confirmed — Doctor Appliance'
        && $mail->details['policy'] === 'We require 24 hours cancellation notice to avoid an $85 service call charge.');

    $job = ($this->latest)();
    expect($job->confirmation_sms_status)->toBe('sent')->and($job->confirmation_email_status)->toBe('sent')
        ->and($job->confirmation_error)->toBeNull();
    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('job.confirmation.sms', 'sent')->where('job.confirmation.email', 'sent'));
    // The SMS is in the customer's SMS chat.
    expect(inCompany($this->company, fn () => Message::query()->where('channel', Message::SMS)->value('customer_id')))->toBe($this->customer->id);
});

test('without a fee the policy says "a service call charge"', function () {
    $this->put(route('company.booking-confirmation.update'), [
        ...BookingConfirmationTexts::defaults(), 'cancellation_fee' => null,
    ])->assertSessionHasNoErrors();

    ($this->book)();

    expect(($this->sms)())->toContain('We require 24 hours cancellation notice to avoid a service call charge.')
        ->not->toContain('$');
});

test('changed texts, hours and fee apply to the next confirmation at once; Reset to default stores nothing', function () {
    $this->put(route('company.booking-confirmation.update'), [
        ...BookingConfirmationTexts::defaults(),
        'sms_text' => 'Hello {first_name}! {company_name} comes {day} {time_window}. {cancellation_policy}',
        'cancellation_hours' => 48,
        'cancellation_fee' => '120',
    ])->assertSessionHasNoErrors();

    ($this->book)();
    expect(($this->sms)())->toBe('Hello Paula! Doctor Appliance comes Friday 1:00 p.m. - 3:00 p.m.. We require 48 hours cancellation notice to avoid an $120 service call charge.');

    // Back to the default text: nothing own is stored.
    $this->put(route('company.booking-confirmation.update'), BookingConfirmationTexts::defaults())->assertSessionHasNoErrors();
    expect($this->company->fresh()->booking_confirmation)->not->toHaveKey('sms_text');
    $this->get(route('company.booking-confirmation.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('company/booking-confirmation')
        ->where('settings.sms_text', BookingConfirmationTexts::defaults()['sms_text'])
        ->where('settings.cancellation_hours', 24)
        ->where('settings.cancellation_fee', '85'));
});

test('By SMS off sends no text; By email off sends no email; the whole switch off sends nothing', function () {
    $this->put(route('company.booking-confirmation.update'), [...BookingConfirmationTexts::defaults(), 'by_sms' => false]);
    ($this->book)();
    expect(($this->sms)())->toBeNull();
    Mail::assertQueued(BookingConfirmationMail::class, 1);

    $this->put(route('company.booking-confirmation.update'), [...BookingConfirmationTexts::defaults(), 'by_email' => false]);
    ($this->book)();
    expect(($this->sms)())->not->toBeNull();
    Mail::assertQueued(BookingConfirmationMail::class, 1);

    $this->put(route('company.booking-confirmation.update'), [...BookingConfirmationTexts::defaults(), 'enabled' => false]);
    $before = inCompany($this->company, fn () => Message::query()->count());
    ($this->book)();
    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe($before)
        ->and(($this->latest)()->confirmation_sms_status)->toBe('off');
});

test('Save as draft sends nothing', function () {
    ($this->book)(['draft' => true]);

    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
    Mail::assertNothingQueued();
});

test('a booking without a time, a day or an address has no gaps and never says null', function () {
    // No time: the day stays, the window is "exact time will be confirmed shortly".
    ($this->book)(['add_visit' => false, 'planned_date' => '2026-10-16']);
    expect(($this->sms)())->toContain('confirmed for Friday, October 16, exact time will be confirmed shortly at 500 Rental Ave')
        ->not->toContain('null')->not->toContain('  ')->not->toContain(' ,');

    // No day and no time.
    ($this->book)(['add_visit' => false]);
    expect(($this->sms)())->toContain('Your appointment is confirmed, we will confirm the date and time shortly at 500 Rental Ave')
        ->not->toContain('null')->not->toContain('  ');

    // No address and no appliance.
    $bare = Customer::factory()->for($this->company)->withPhone('604-555-0150')->create(['first_name' => 'Bob']);
    $this->post(route('jobs.store'), [
        'quick_booking' => true, 'brand_id' => $this->brand->id, 'new_customer_mode' => false, 'customer_id' => $bare->id,
        'add_visit' => true, 'visit' => ['date' => '2026-10-16', 'start_time' => '09:00', 'end_time' => '11:00'],
    ])->assertSessionHasNoErrors();
    expect(($this->sms)())->toStartWith('Hi Bob, this is Doctor Appliance. Your appointment is confirmed for Friday, October 16, 9:00 a.m. - 11:00 a.m.. We require')
        ->not->toContain(' at ')->not->toContain('service.')->not->toContain('null');
});

test('Send test to me texts and emails the owner with an example customer', function () {
    $this->post(route('company.booking-confirmation.test'))->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request['To'] === '+16045550199' && str_contains($request['Body'], 'Hi Maria'));
    Mail::assertQueued(BookingConfirmationMail::class, fn (BookingConfirmationMail $mail) => $mail->hasTo('owner@example.com') && $mail->jobId === null);
    expect(session('inertia.flash_data.toast.type'))->toBe('success');
});

test('Resend confirmation and the reschedule "Notify client" send the new day and time', function () {
    ($this->book)();
    $job = ($this->latest)();
    $visit = inCompany($this->company, fn () => $job->visits()->sole());

    $this->put(route('visits.update', $visit), ['date' => '2026-10-17', 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => [$this->owner->id], 'notify' => true])
        ->assertSessionHasNoErrors();
    expect(($this->sms)())->toContain('Saturday, October 17, 9:00 a.m. - 11:00 a.m.')->toContain('cancellation notice');

    $this->post(route('jobs.booking-confirmation.send', $job))->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Message::query()->where('channel', Message::SMS)->where('kind', 'booking_confirmation')->count()))->toBe(3);
});

test('no phone and no email: the job shows why nothing went', function () {
    $bare = Customer::factory()->for($this->company)->create(['first_name' => 'Nobody']);
    $this->post(route('jobs.store'), [
        'quick_booking' => true, 'brand_id' => $this->brand->id, 'new_customer_mode' => false, 'customer_id' => $bare->id,
        'add_visit' => true, 'visit' => ['date' => '2026-10-16', 'start_time' => '09:00', 'end_time' => '11:00'],
    ])->assertSessionHasNoErrors();

    $job = ($this->latest)();
    expect($job->confirmation_error)->toBe(__('booking_confirmation.status.no_contact'));
    expect(inCompany($this->company, fn () => app(BookingConfirmation::class)->plan($job)['channel']))->toBeNull();
});

test('only the Owner edits the settings; another company keeps its own', function () {
    $tech = memberOf($this->company, UserRole::Technician);
    $this->actingAs($tech)->get(route('company.booking-confirmation.edit'))->assertForbidden();
    $this->actingAs($tech)->put(route('company.booking-confirmation.update'), BookingConfirmationTexts::defaults())->assertForbidden();

    $other = Company::factory()->create();
    $this->actingAs($this->owner)->put(route('company.booking-confirmation.update'), [...BookingConfirmationTexts::defaults(), 'cancellation_hours' => 12]);
    expect($other->fresh()->booking_confirmation)->toBeNull();
});

test('the appliance is named in the sentence when the job has one', function () {
    $text = inCompany($this->company, fn () => BookingConfirmationTexts::render($this->company, BookingConfirmationTexts::defaults()['sms_text'], [
        'first_name' => 'Paula', 'company_name' => 'Doctor Appliance', 'day' => 'Friday', 'date' => 'October 16',
        'time_window' => '1:00 p.m. - 3:00 p.m.', 'address' => '500 Rental Ave', 'appliance' => 'dishwasher', 'company_phone' => '(604) 739-8024',
    ]));

    expect($text)->toContain('at 500 Rental Ave for dishwasher service. We require 24 hours');
});

test('the settings form saves completely empty', function () {
    $this->put(route('company.booking-confirmation.update'), [])->assertSessionHasNoErrors();

    $settings = BookingConfirmationTexts::settings($this->company->fresh());
    expect($settings['sms_text'])->toBe(BookingConfirmationTexts::defaults()['sms_text'])
        ->and($settings['cancellation_hours'])->toBeNull()->and($settings['cancellation_fee'])->toBeNull();
});
