<?php

use App\Enums\UserRole;
use App\Mail\CustomerMessageMail;
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

/**
 * Send Booking Confirmation: by email or SMS, always to the customer (the one who pays), never to the occupant.
 */
beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/2010-04-01/Accounts/ACsub/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));

    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'country' => 'CA', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->withEmail('payer@example.com')
        ->create(['first_name' => 'Paula', 'last_name' => 'Payer']);
    $this->property = Property::factory()->for($this->customer)->create([
        'line1' => '500 Rental Ave', 'city' => 'Surrey', 'site_contact_name' => 'Tina Tenant', 'site_contact_phone' => '+16045550177',
    ]);
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-15 09:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-15 11:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id]);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
    $this->actingAs($this->owner);
});

test('the preview names the day, window and address and shows where it can go', function () {
    $this->getJson(route('jobs.booking-confirmation.show', $this->job))
        ->assertOk()
        ->assertJsonPath('email', 'payer@example.com')
        ->assertJsonPath('sms.to', '+16045550142')
        ->assertJsonPath('sms.blocked', null)
        ->assertJson(fn ($json) => $json->where('body', fn (string $body) => str_contains($body, 'Hi Paula')
            && str_contains($body, 'Doctor Appliance')
            && str_contains($body, 'Thursday, Oct 15')
            && str_contains($body, '500 Rental Ave'))->etc());
});

test('by SMS it goes to the customer\'s phone, not the occupant\'s', function () {
    $this->post(route('jobs.booking-confirmation.send', $this->job), ['channel' => 'sms'])->assertSessionHasNoErrors();

    $message = inCompany($this->company, fn () => Message::query()->sole());
    expect($message->kind->value)->toBe('booking_confirmation')
        ->and($message->to)->toBe('+16045550142')
        ->and($message->channel)->toBe(Message::SMS);
    Http::assertSent(fn (Request $request) => $request['To'] === '+16045550142');
    Http::assertNotSent(fn (Request $request) => ($request['To'] ?? null) === '+16045550177');
});

test('by email it goes to the customer\'s email', function () {
    $this->post(route('jobs.booking-confirmation.send', $this->job), ['channel' => 'email'])->assertSessionHasNoErrors();

    $message = inCompany($this->company, fn () => Message::query()->sole());
    expect($message->channel)->toBe(Message::EMAIL)->and($message->to)->toBe('payer@example.com');
    Mail::assertQueued(CustomerMessageMail::class, fn (CustomerMessageMail $mail) => $mail->hasTo('payer@example.com'));
});

test('without an email the email is recorded as not sent', function () {
    inCompany($this->company, fn () => $this->customer->emails()->delete());

    $this->post(route('jobs.booking-confirmation.send', $this->job), ['channel' => 'email']);

    expect(inCompany($this->company, fn () => Message::query()->sole()->status))->toBe('blocked');
    Mail::assertNothingQueued();
});

test('SMS from the app only in Automatic mode', function () {
    $this->company->update(['sms_mode' => 'off']);

    $this->getJson(route('jobs.booking-confirmation.show', $this->job))->assertJsonPath('sms.mode', 'off')
        ->assertJsonPath('sms.blocked', __('jobs.confirmation.sms_off'));
    $this->post(route('jobs.booking-confirmation.send', $this->job), ['channel' => 'sms'])->assertNotFound();
});

test('a booking opens the confirmation prompt', function () {
    $this->post(route('jobs.store'), [
        'quick_booking' => true, 'brand_id' => $this->brand->id, 'job_type' => 'repair',
        'new_customer_mode' => false, 'customer_id' => $this->customer->id, 'property_id' => $this->property->id,
        'add_visit' => true,
        'visit' => ['date' => '2026-10-16', 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => [$this->owner->id]],
    ])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::query()->latest('id')->first());
    expect(session('inertia.flash_data.booking_confirmation'))->toMatchArray(['job_id' => $job->id, 'number' => $job->number]);
});

test('another company\'s job is not found and a collector cannot send', function () {
    $stranger = memberOf(Company::factory()->create());
    $collector = memberOf($this->company, UserRole::Collector);
    $this->actingAs($stranger)->getJson(route('jobs.booking-confirmation.show', $this->job))->assertNotFound();

    $this->actingAs($collector)
        ->post(route('jobs.booking-confirmation.send', $this->job), ['channel' => 'email'])->assertForbidden();
    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
});
