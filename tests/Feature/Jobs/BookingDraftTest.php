<?php

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Finishing a booking (owner's task, Oct 24): Finish booking sends the confirmation by itself, as the company's SMS
 * setting says; Save as draft sends nothing and leaves an orange Draft until "Finish booking" on the job page; a
 * finished booking that moves asks "Notify client?".
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
});

test('Finish booking schedules the job and texts the confirmation by itself', function () {
    ($this->book)()->assertSessionHasNoErrors();
    $job = ($this->latest)();

    expect($job->status)->toBe(JobStatus::Scheduled);
    $message = inCompany($this->company, fn () => Message::query()->sole());
    expect($message->kind->value)->toBe('booking_confirmation')
        ->and($message->channel)->toBe(Message::SMS)
        ->and($message->to)->toBe('+16045550142')
        ->and($message->body)->toContain('1:00 p.m. - 3:00 p.m.');
    Http::assertSent(fn (Request $request) => $request['To'] === '+16045550142');

    expect(session('inertia.flash_data.booking_confirmation'))->toMatchArray(['job_id' => $job->id, 'draft' => false])
        ->and(session('inertia.flash_data.booking_confirmation.sent.channel'))->toBe('sms');
});

test('Save as draft keeps the booking as a draft and sends nothing', function () {
    ($this->book)(['draft' => true])->assertSessionHasNoErrors();
    $job = ($this->latest)();

    expect($job->status)->toBe(JobStatus::Draft)
        ->and($job->visits()->count())->toBe(1)
        ->and(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
    expect(session('inertia.flash_data.booking_confirmation'))->toMatchArray(['job_id' => $job->id, 'draft' => true, 'sent' => null]);
    Http::assertNothingSent();
    Mail::assertNothingQueued();
});

test('with SMS off Finish booking sends nothing', function () {
    $this->company->update(['sms_mode' => 'off']);

    ($this->book)()->assertSessionHasNoErrors();

    expect(($this->latest)()->status)->toBe(JobStatus::Scheduled)
        ->and(inCompany($this->company, fn () => Message::query()->count()))->toBe(0)
        ->and(session('inertia.flash_data.booking_confirmation.sent.channel'))->toBeNull();
    Http::assertNothingSent();
    Mail::assertNothingQueued();
});

test('drafts are counted in the unfinished-jobs bar and shown as drafts everywhere', function () {
    ($this->book)(['draft' => true]);
    $job = ($this->latest)();

    $this->get(route('jobs.index', ['tab' => 'open']))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.counts.draft', 1)
        ->where('jobs.data.0.id', $job->id)
        ->where('jobs.data.0.backlog_reason', 'draft')
        ->where('jobs.data.0.status', 'booking_draft'));

    $this->get(route('calendar', ['view' => 'day', 'date' => '2026-10-16']))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.job.status', 'booking_draft')
        ->where('visits.0.job.status_label', 'Draft'));

    $this->get(route('jobs.mine', ['date' => '2026-10-16']))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.job.status', 'booking_draft'));
});

test('Finish booking on the job page schedules the draft and sends the confirmation', function () {
    ($this->book)(['draft' => true]);
    $job = ($this->latest)();

    $this->post(route('jobs.finish-booking', $job))->assertSessionHasNoErrors();

    expect($job->fresh()->status)->toBe(JobStatus::Scheduled);
    expect(inCompany($this->company, fn () => Message::query()->sole()->channel))->toBe(Message::SMS);
    expect(session('inertia.flash_data.booking_confirmation.sent.channel'))->toBe('sms');

    // Twice does nothing more.
    $this->post(route('jobs.finish-booking', $job));
    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(1);
});

test('a draft cannot be resent or set by hand, and another company cannot finish it', function () {
    $stranger = memberOf(Company::factory()->create());
    ($this->book)(['draft' => true]);
    $job = ($this->latest)();

    $this->post(route('jobs.booking-confirmation.send', $job))->assertSessionHasErrors('job');
    $this->put(route('jobs.status', ($this->latest)()), ['status' => 'booking_draft'])->assertSessionHasErrors('status');

    $this->actingAs($stranger)->post(route('jobs.finish-booking', $job))->assertNotFound();
    expect($job->fresh()->status)->toBe(JobStatus::Draft)
        ->and(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
});

test('moving a finished booking asks "Notify client?"; a draft or an unchanged time does not', function () {
    ($this->book)();
    $job = ($this->latest)();
    $visit = inCompany($this->company, fn () => JobVisit::query()->where('service_job_id', $job->id)->sole());
    $payload = ['date' => '2026-10-16', 'start_time' => '13:00', 'end_time' => '15:00', 'assignee_ids' => [$this->owner->id], 'back' => true];

    $this->put(route('visits.update', $visit), $payload)->assertSessionHasNoErrors();
    expect(session('inertia.flash_data.notify_client'))->toBeNull();

    session()->forget('inertia.flash_data');
    $this->put(route('visits.update', $visit), [...$payload, 'start_time' => '15:00', 'end_time' => '17:00'])->assertSessionHasNoErrors();
    expect(session('inertia.flash_data.notify_client'))->toMatchArray(['job_id' => $job->id]);

    $this->put(route('visits.move', $visit), ['date' => '2026-10-17', 'start_time' => '09:00'])->assertSessionHasNoErrors();
    expect(session('inertia.flash_data.notify_client'))->toMatchArray(['job_id' => $job->id]);

    // Flash data stays in the test session until a page shows it.
    session()->forget('inertia.flash_data');
    ($this->book)(['draft' => true]);
    $draft = ($this->latest)();
    $draftVisit = inCompany($this->company, fn () => JobVisit::query()->where('service_job_id', $draft->id)->sole());
    $this->put(route('visits.update', $draftVisit), [...$payload, 'start_time' => '16:00', 'end_time' => '18:00'])->assertSessionHasNoErrors();
    expect(session('inertia.flash_data.notify_client'))->toBeNull();
});

test('a draft gets no reminder the day before; once finished it does', function () {
    ($this->book)(['draft' => true]);
    $job = ($this->latest)();

    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'America/Vancouver'));
    $this->artisan('messages:send-visit-reminders', ['--force' => true])->assertSuccessful();
    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);

    $this->post(route('jobs.finish-booking', $job));
    $this->artisan('messages:send-visit-reminders', ['--force' => true])->assertSuccessful();
    expect(inCompany($this->company, fn () => Message::query()->pluck('kind')->map->value->sort()->values()->all()))
        ->toBe(['booking_confirmation', 'visit_reminder']);
});

test('the status list offers no Draft', function () {
    expect(collect(JobStatus::manual())->contains(JobStatus::Draft))->toBeFalse()
        ->and(JobStatus::Draft->label())->toBe('Draft');
});
