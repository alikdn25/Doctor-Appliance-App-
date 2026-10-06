<?php

use App\Enums\UserRole;
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

    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->profile = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Surrey', 'review_url' => 'https://g.page/r/surrey/review']));
    $this->brand->update(['google_profile_id' => $this->profile->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->withEmail('jane@example.com')->create(['first_name' => 'Jane']);
    $this->actingAs($this->owner);
});

function invoiceForJob(string $status = 'completed'): Invoice
{
    $job = ServiceJob::factory()->for(Property::factory()->for(test()->customer))
        ->create(['brand_id' => test()->brand->id, 'status' => $status]);
    test()->post(route('invoices.store', $job), documentPayload())->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => Invoice::latest('id')->first());
}

function automaticSms(): void
{
    config(['services.twilio.account_sid' => 'ACmaster', 'services.twilio.auth_token' => 't']);
    Http::fake(['*/Messages.json' => Http::response(['sid' => 'SMr'], 201)]);
    test()->company->update(['sms_mode' => 'automatic']);
    inCompany(test()->company, fn () => SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 's', 'phone_number' => '+16045550100']));
}

test('nothing is sent automatically when the job is paid in full', function () {
    $invoice = invoiceForJob();
    $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();

    $this->travel(1)->days();
    $this->artisan('messages:deliver-due');

    expect(inCompany($this->company, fn () => ReviewRequest::count()))->toBe(0)
        ->and(inCompany($this->company, fn () => Message::count()))->toBe(0);
    Mail::assertNothingQueued();
});

test('the invoice page offers the review prompt with the locations and the customer phone', function () {
    $burnaby = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Burnaby', 'review_url' => 'https://g.page/r/burnaby/review']));
    $invoice = invoiceForJob();

    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('reviewPrompt.url', route('invoices.review-request', $invoice))
        ->where('reviewPrompt.phone', '+16045550142')
        ->where('reviewPrompt.default_profile_id', $this->profile->id)
        ->has('reviewPrompt.profiles', 2)
        ->where('reviewPrompt.profiles.0.label', 'Burnaby')
        ->where('reviewPrompt.profiles.0.id', $burnaby->id)
        ->where('reviewPrompt.profiles.0.text', fn (string $text) => str_contains($text, 'https://g.page/r/burnaby/review'))
        ->where('reviewPrompt.profiles.1.label', 'Surrey'));

    // The job page has no review switch or button any more.
    $this->get(route('jobs.show', $invoice->service_job_id))->assertInertia(fn (Assert $page) => $page
        ->missing('messaging.review')
        ->missing('messaging.texts.review_request'));
});

test('in Automatic mode "Send" texts the chosen location\'s link to the chosen phone as a separate SMS', function () {
    automaticSms();
    $burnaby = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Burnaby', 'review_url' => 'https://g.page/r/burnaby/review']));
    $invoice = invoiceForJob('invoiced');

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $burnaby->id, 'phone' => '(604) 555-0199'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $message = inCompany($this->company, fn () => Message::sole());
    expect($message)->kind->value->toBe('review_request')->channel->toBe('sms')->status->toBe('sent')
        ->to->toBe('+16045550199')->customer_id->toBe($this->customer->id)->service_job_id->toBe($invoice->service_job_id)
        ->and($message->body)->toBe('Hi Jane, thank you for choosing Doctor Appliance! Would you take a moment to review us on Google? https://g.page/r/burnaby/review');
    Http::assertSent(fn ($r) => ($r['To'] ?? null) === '+16045550199' && str_contains($r['Body'] ?? '', 'https://g.page/r/burnaby/review'));

    expect(inCompany($this->company, fn () => ReviewRequest::sole()))
        ->status->toBe('sent')->channel->toBe('sms')->google_profile_id->toBe($burnaby->id)->message_id->toBe($message->id);
});

test('the customer\'s own phone is texted as that phone, and a later send replaces the job\'s request', function () {
    automaticSms();
    $invoice = invoiceForJob();

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'phone' => '+16045550142'])->assertSessionHasNoErrors();
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'phone' => '+16045550142'])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Message::where('kind', 'review_request')->where('status', 'sent')->count()))->toBe(2)
        ->and(inCompany($this->company, fn () => ReviewRequest::count()))->toBe(1);
});

test('an opted-out number is not texted', function () {
    automaticSms();
    inCompany($this->company, fn () => $this->customer->phones()->update(['sms_opted_out_at' => now()]));
    $invoice = invoiceForJob();

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'phone' => '+16045550142'])
        ->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Message::sole()))->status->toBe('blocked')
        ->and(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('skipped');
    Http::assertNothingSent();
});

test('from the technician\'s phone "Send" is recorded as opened on the phone', function () {
    $invoice = invoiceForJob();

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'phone' => '604-555-0142'])
        ->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Message::sole()))->channel->toBe('technician_phone')->status->toBe('opened')->to->toBe('+16045550142')
        ->and(inCompany($this->company, fn () => ReviewRequest::sole()))->status->toBe('sent')->channel->toBe('technician_phone');
});

test('the prompt needs a location of the company, a valid phone and texting on', function () {
    $invoice = invoiceForJob();
    $foreign = inCompany(Company::factory()->create(), fn () => GoogleProfile::create(['label' => 'Elsewhere', 'review_url' => 'https://g.page/r/x/review']));

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $foreign->id, 'phone' => '+16045550142'])
        ->assertSessionHasErrors('google_profile_id');
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'phone' => '12'])
        ->assertSessionHasErrors('phone');

    $this->company->update(['sms_mode' => 'off']);
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'phone' => '+16045550142'])
        ->assertSessionHasErrors('phone');

    expect(inCompany($this->company, fn () => Message::count()))->toBe(0);
});

test('review requests cannot be opened from the job any more', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id]);

    $this->post(route('jobs.messages.opened', $job), ['kind' => 'review_request', 'to' => '+16045550142', 'body' => 'x'])
        ->assertSessionHasErrors('kind');
    expect(inCompany($this->company, fn () => ReviewRequest::count()))->toBe(0);
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
