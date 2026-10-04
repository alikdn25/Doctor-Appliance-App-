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
        ->create(['brand_id' => test()->brand->id, 'status' => 'completed']);
    test()->post(route('invoices.store', $job), documentPayload());
    $invoice = inCompany(test()->company, fn () => Invoice::latest('id')->first());
    test()->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();

    return $job->fresh();
}

test('the review request is offered at the end, on the paid invoice, with the locations to choose from', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'status' => 'completed']);
    $this->post(route('invoices.store', $job), documentPayload());
    $invoice = inCompany($this->company, fn () => Invoice::latest('id')->first());

    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->missing('messaging.review'));
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page->where('review', null));

    $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();
    // Payment alone never sends or schedules anything: the technician sends it with the link they choose.
    expect(inCompany($this->company, fn () => ReviewRequest::count()))->toBe(0);

    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('review.job_id', $job->id)
        ->where('review.locations', [['id' => $this->profile->id, 'label' => 'Surrey', 'url' => 'https://g.page/r/surrey/review']])
        ->where('review.text', fn (string $text) => str_contains($text, '{review_link}') && ! str_contains($text, 'surrey')));
});

test('in Off mode the chosen location\'s link goes by email and is recorded', function () {
    $this->company->update(['sms_mode' => 'off']);
    $otherBrand = Brand::factory()->create(['company_id' => $this->company->id]);
    [$burnaby, $elsewhere] = inCompany($this->company, fn () => [
        GoogleProfile::create(['label' => 'Burnaby', 'review_url' => 'https://g.page/r/burnaby-other/review']),
        GoogleProfile::create(['label' => 'Other brand', 'review_url' => 'https://g.page/r/other/review', 'brand_id' => $otherBrand->id]),
    ]);
    $job = paidJob();

    $this->post(route('jobs.review-request', $job), [])->assertSessionHasErrors('location_id');
    // A location tied to another brand is not offered for this job.
    $this->post(route('jobs.review-request', $job), ['location_id' => $elsewhere->id])->assertSessionHasErrors('location_id');
    $this->post(route('jobs.review-request', $job), ['location_id' => $burnaby->id])->assertSessionHasNoErrors();

    $message = inCompany($this->company, fn () => Message::sole());
    expect($message->kind->value)->toBe('review_request')->and($message->channel)->toBe('email')
        ->and($message->body)->toBe('Hi Jane, thank you for choosing Doctor Appliance! Would you take a moment to review us on Google? https://g.page/r/burnaby-other/review')
        ->and(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('sent')->google_profile_id->toBeNull();
    Mail::assertQueued(CustomerMessageMail::class, fn ($mail) => $mail->hasTo('jane@example.com'));

    $invoice = inCompany($this->company, fn () => Invoice::where('service_job_id', $job->id)->sole());
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('review.status', fn (string $s) => str_starts_with($s, 'Review request sent'))
        ->where('review.sent', true));
});

test('in Automatic mode the request goes by SMS with the chosen link', function () {
    config(['services.twilio.account_sid' => 'ACmaster', 'services.twilio.auth_token' => 't']);
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SMr'], 201)]);
    $this->company->update(['sms_mode' => 'automatic', 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '00:00']);
    inCompany($this->company, fn () => SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 's', 'phone_number' => '+16045550100']));

    $job = paidJob();
    $this->post(route('jobs.review-request', $job), ['location_id' => $this->profile->id])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('sent')->channel->toBe('sms');
    Http::assertSent(fn ($r) => str_contains($r['Body'] ?? '', 'https://g.page/r/surrey/review'));
});

test('from the technician\'s phone the text with the chosen link is opened and recorded', function () {
    $job = paidJob();
    $this->post(route('jobs.review-request', $job), ['location_id' => $this->profile->id])->assertNotFound();

    $this->post(route('jobs.messages.opened', $job), [
        'kind' => 'review_request', 'to' => '+16045550142', 'body' => 'Please review us: https://g.page/r/any/review',
    ])->assertRedirect();

    expect(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('sent')->channel->toBe('technician_phone')
        ->and(inCompany($this->company, fn () => Message::sole())->body)->toBe('Please review us: https://g.page/r/any/review');
});

test('a request scheduled by an earlier version is still delivered when due', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'status' => 'paid', 'ask_for_review' => true]);
    $request = inCompany($this->company, fn () => ReviewRequest::create([
        'customer_id' => $this->customer->id, 'service_job_id' => $job->id, 'google_profile_id' => $this->profile->id,
        'status' => ReviewRequest::SCHEDULED, 'send_after' => now()->subMinute(),
    ]));

    $this->artisan('messages:deliver-due');

    expect($request->fresh()->status)->toBe('sent')
        ->and(inCompany($this->company, fn () => Message::sole())->body)->toContain('https://g.page/r/surrey/review');
});

test('locations are added and removed by the Owner only, optionally tied to a brand', function () {
    $admin = memberOf($this->company, UserRole::Admin);
    $this->actingAs($admin)->get(route('company.google-profiles.edit'))->assertForbidden();
    $this->put(route('company.google-profiles.update'), ['profiles' => []])->assertForbidden();
    $this->actingAs($this->owner);

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
