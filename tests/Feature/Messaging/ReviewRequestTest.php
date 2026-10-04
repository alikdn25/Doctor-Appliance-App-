<?php

use App\Enums\UserRole;
use App\Mail\CustomerMessageMail;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Property;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/Vancouver'));

    $this->company = Company::factory()->create(['review_request_delay_hours' => 2, 'review_request_cooldown_days' => 180]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->profile = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Surrey', 'review_url' => 'https://g.page/r/surrey/review']));
    $this->brand->update(['google_profile_id' => $this->profile->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->withEmail('jane@example.com')->create(['first_name' => 'Jane']);
    $this->actingAs($this->owner);
});

function paidJob(): ServiceJob
{
    $job = ServiceJob::factory()->for(Property::factory()->for(test()->customer))
        ->create(['brand_id' => test()->brand->id, 'status' => 'completed', 'ask_for_review' => true]);
    test()->post(route('invoices.store', $job), documentPayload());
    $invoice = inCompany(test()->company, fn () => Invoice::latest('id')->first());
    test()->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();

    return $job->fresh();
}

test('a review request is scheduled when the job is paid in full and sent after the delay', function () {
    $job = paidJob();

    $request = inCompany($this->company, fn () => ReviewRequest::sole());
    expect($request)->status->toBe('scheduled')->google_profile_id->toBe($this->profile->id)
        ->and($request->send_after->equalTo(now()->addHours(2)))->toBeTrue();

    $this->artisan('messages:deliver-due');
    expect($request->fresh()->status)->toBe('scheduled');

    $this->travel(2)->hours();
    $this->artisan('messages:deliver-due');

    // Technician's phone mode (the default): automated messages go by email.
    $message = inCompany($this->company, fn () => Message::sole());
    expect($request->fresh())->status->toBe('sent')->channel->toBe('email')
        ->and($message->kind->value)->toBe('review_request')
        ->and($message->body)->toBe('Hi Jane, thank you for choosing Doctor Appliance! Would you take a moment to review us on Google? https://g.page/r/surrey/review');
    Mail::assertQueued(CustomerMessageMail::class, fn ($mail) => $mail->hasTo('jane@example.com'));

    $invoice = inCompany($this->company, fn () => Invoice::where('service_job_id', $job->id)->sole());
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('review.status', fn (string $s) => str_starts_with($s, 'Review request sent'))
        ->where('review.sent', true));
});

test('in Automatic mode the request goes by SMS', function () {
    config(['services.twilio.account_sid' => 'ACmaster', 'services.twilio.auth_token' => 't']);
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SMr'], 201)]);
    $this->company->update(['sms_mode' => 'automatic']);
    inCompany($this->company, fn () => SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 's', 'phone_number' => '+16045550100']));

    paidJob();
    $this->travel(3)->hours();
    $this->artisan('messages:deliver-due');

    expect(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('sent')->channel->toBe('sms');
    Http::assertSent(fn ($r) => str_contains($r['Body'] ?? '', 'https://g.page/r/surrey/review'));
});

test('a customer gets at most one request in the cooldown period', function () {
    paidJob();
    $this->travel(3)->hours();
    $this->artisan('messages:deliver-due');

    $second = paidJob();
    $request = inCompany($this->company, fn () => ReviewRequest::where('service_job_id', $second->id)->sole());

    expect($request)->status->toBe('skipped')->skip_reason->toContain('180 days');
});

test('no request when the job is not asked for one, or there is no Google profile', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for($this->customer))
        ->create(['brand_id' => $this->brand->id, 'status' => 'completed', 'ask_for_review' => false]);
    $this->post(route('invoices.store', $job), documentPayload());
    $this->post(route('payments.store', inCompany($this->company, fn () => Invoice::sole())), ['amount' => '280.50', 'method' => 'cash']);
    expect(inCompany($this->company, fn () => ReviewRequest::count()))->toBe(0);

    inCompany($this->company, fn () => GoogleProfile::query()->delete());
    $job = paidJob();
    expect(inCompany($this->company, fn () => ReviewRequest::where('service_job_id', $job->id)->sole()))
        ->status->toBe('skipped')->skip_reason->toBe('No Google profile is set up.');
});

test('"Ask for a review" defaults from the company and can be switched on the job', function () {
    $this->company->update(['review_requests_default' => false]);
    $property = Property::factory()->for($this->customer)->create();

    $this->post(route('jobs.store'), [
        'brand_id' => $this->brand->id, 'job_type' => 'repair', 'customer_id' => $this->customer->id, 'property_id' => $property->id,
    ])->assertSessionHasNoErrors();
    $job = inCompany($this->company, fn () => ServiceJob::latest('id')->first());
    expect($job->ask_for_review)->toBeFalse();

    $this->put(route('jobs.ask-for-review', $job), ['ask' => true])->assertRedirect();
    expect($job->fresh()->ask_for_review)->toBeTrue();
});

test('the review request is offered at the end, on the paid invoice, not on the job screen', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for($this->customer))
        ->create(['brand_id' => $this->brand->id, 'status' => 'completed', 'ask_for_review' => true]);
    $this->post(route('invoices.store', $job), documentPayload());
    $invoice = inCompany($this->company, fn () => Invoice::latest('id')->first());

    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->missing('messaging.review'));
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page->where('review', null));

    $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('review.job_id', $job->id)
        ->where('review.has_profile', true)
        ->where('review.text', fn (string $text) => str_contains($text, 'https://g.page/r/surrey/review')));
});

test('switching "Ask for a review" on after payment schedules the request', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for($this->customer))
        ->create(['brand_id' => $this->brand->id, 'status' => 'completed', 'ask_for_review' => false]);
    $this->post(route('invoices.store', $job), documentPayload());
    $this->post(route('payments.store', inCompany($this->company, fn () => Invoice::sole())), ['amount' => '280.50', 'method' => 'cash']);
    expect(inCompany($this->company, fn () => ReviewRequest::count()))->toBe(0);

    $this->put(route('jobs.ask-for-review', $job), ['ask' => true])->assertRedirect();
    expect(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('scheduled');
});

test('from the technician\'s phone the request is sent with the button and recorded', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'ask_for_review' => true]);

    $this->post(route('jobs.messages.opened', $job), ['kind' => 'review_request', 'to' => '+16045550142', 'body' => 'x'])->assertRedirect();

    expect(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('sent')->channel->toBe('technician_phone');
});

test('Google profiles are edited by the office; the brand picks its default', function () {
    $this->put(route('company.google-profiles.update'), ['profiles' => [
        ['id' => $this->profile->id, 'label' => 'Surrey', 'review_url' => 'https://g.page/r/surrey/review', 'brand_id' => $this->brand->id],
        ['id' => null, 'label' => 'Burnaby', 'review_url' => 'http://insecure.example.com', 'brand_id' => null],
    ]])->assertSessionHasErrors('profiles.1.review_url');

    $this->put(route('company.google-profiles.update'), ['profiles' => [
        ['id' => $this->profile->id, 'label' => 'Surrey', 'review_url' => 'https://g.page/r/surrey/review', 'brand_id' => $this->brand->id],
        ['id' => null, 'label' => 'Burnaby', 'review_url' => 'https://g.page/r/burnaby/review', 'brand_id' => null],
    ]])->assertRedirect(route('company.google-profiles.edit'));

    expect(inCompany($this->company, fn () => GoogleProfile::count()))->toBe(2);
    $this->get(route('brands.edit', $this->brand))->assertInertia(fn (Assert $page) => $page->has('googleProfiles', 2));
});
