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

test('the invoice page offers the review prompt with the locations and the customer email', function () {
    $burnaby = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Burnaby', 'review_url' => 'https://g.page/r/burnaby/review']));
    $invoice = invoiceForJob();

    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('reviewPrompt.url', route('invoices.review-request', $invoice))
        ->where('reviewPrompt.email', 'jane@example.com')
        ->missing('reviewPrompt.phone')
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

test('"Send" emails the chosen location\'s link with the review picture; never an SMS (owner\'s task of November 22)', function () {
    automaticSms();
    $burnaby = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Burnaby', 'review_url' => 'https://g.page/r/burnaby/review']));
    $invoice = invoiceForJob('invoiced');

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $burnaby->id, 'email' => 'jane@example.com'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $message = inCompany($this->company, fn () => Message::sole());
    expect($message)->kind->value->toBe('review_request')->channel->toBe('email')->status->toBe('sent')
        ->to->toBe('jane@example.com')->customer_id->toBe($this->customer->id)->service_job_id->toBe($invoice->service_job_id)
        ->and($message->body)->toBe('Hi Jane, thank you for choosing Doctor Appliance! Would you take a moment to review us on Google? https://g.page/r/burnaby/review');
    Http::assertNothingSent();
    Mail::assertQueued(CustomerMessageMail::class, fn (CustomerMessageMail $mail) => $mail->hasTo('jane@example.com') && $mail->reviewUrl === 'https://g.page/r/burnaby/review');

    expect(inCompany($this->company, fn () => ReviewRequest::sole()))
        ->status->toBe('sent')->channel->toBe('email')->google_profile_id->toBe($burnaby->id)->message_id->toBe($message->id);
});

test('the review email shows the review block with the picture', function () {
    $invoice = invoiceForJob();
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'email' => 'jane@example.com'])->assertSessionHasNoErrors();
    $message = inCompany($this->company, fn () => Message::sole());

    $html = (new CustomerMessageMail($message->id, $this->company->id, 'Review', 'https://g.page/r/surrey/review'))->render();
    expect($html)->toContain('https://g.page/r/surrey/review')->toContain(__('documents.mail.review.cta'));
});

test('a later send replaces the job\'s request; an email typed for a customer without one is kept', function () {
    $customer = Customer::factory()->for($this->company)->create(['first_name' => 'Bob']);
    $this->customer = $customer;
    $invoice = invoiceForJob();

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'email' => 'Bob@Example.com'])->assertSessionHasNoErrors();
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'email' => 'bob@example.com'])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Message::where('kind', 'review_request')->where('channel', 'email')->count()))->toBe(2)
        ->and(inCompany($this->company, fn () => ReviewRequest::count()))->toBe(1)
        ->and(inCompany($this->company, fn () => $customer->emails()->pluck('email')->all()))->toBe(['bob@example.com']);
});

test('the prompt needs a location of the company and a valid email; texting being off does not matter', function () {
    $invoice = invoiceForJob();
    $foreign = inCompany(Company::factory()->create(), fn () => GoogleProfile::create(['label' => 'Elsewhere', 'review_url' => 'https://g.page/r/x/review']));

    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $foreign->id, 'email' => 'jane@example.com'])
        ->assertSessionHasErrors('google_profile_id');
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'phone' => '+16045550142'])
        ->assertSessionHasErrors('email');
    expect(inCompany($this->company, fn () => Message::count()))->toBe(0);

    $this->company->update(['sms_mode' => 'off']);
    $this->post(route('invoices.review-request', $invoice), ['google_profile_id' => $this->profile->id, 'email' => 'jane@example.com'])
        ->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Message::count()))->toBe(1);
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

test('a location is marked "Default for <brand>" on the Google reviews page', function () {
    $other = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Handy Pros']);
    $burnaby = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Burnaby', 'review_url' => 'https://g.page/r/burnaby/review', 'brand_id' => $other->id]));
    $row = fn (GoogleProfile $p, int $brand, bool $default) => ['id' => $p->id, 'label' => $p->label, 'review_url' => $p->review_url, 'brand_id' => $brand, 'is_default' => $default];

    $this->get(route('company.google-profiles.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('profiles.0.is_default', false) // Surrey has no brand of its own and the company has two brands.
        ->where('profiles.1.is_default', false));

    $this->put(route('company.google-profiles.update'), ['profiles' => [
        $row($this->profile, $this->brand->id, true),
        $row($burnaby, $other->id, true),
    ]])->assertSessionHasNoErrors();

    expect($this->brand->fresh()->google_profile_id)->toBe($this->profile->id)
        ->and($other->fresh()->google_profile_id)->toBe($burnaby->id);
    $this->get(route('company.google-profiles.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('profiles.0.is_default', true)
        ->where('profiles.1.is_default', true));

    // Unticked: the brand has no default location any more.
    $this->put(route('company.google-profiles.update'), ['profiles' => [
        $row($this->profile, $this->brand->id, true),
        $row($burnaby, $other->id, false),
    ]])->assertSessionHasNoErrors();
    expect($other->fresh()->google_profile_id)->toBeNull()
        ->and($this->brand->fresh()->google_profile_id)->toBe($this->profile->id);
});

test('with one brand, a location without a brand can be its default', function () {
    $this->brand->update(['google_profile_id' => null]);

    $this->put(route('company.google-profiles.update'), ['profiles' => [
        ['id' => $this->profile->id, 'label' => 'Surrey', 'review_url' => 'https://g.page/r/surrey/review', 'brand_id' => null, 'is_default' => true],
    ]])->assertSessionHasNoErrors();

    expect($this->brand->fresh()->google_profile_id)->toBe($this->profile->id);
    $this->get(route('company.google-profiles.edit'))->assertInertia(fn (Assert $page) => $page->where('profiles.0.is_default', true));
});

test('the review text can be changed for this customer; the link goes back at the end and the template stays', function () {
    automaticSms();
    $invoice = invoiceForJob();

    $this->post(route('invoices.review-request', $invoice), [
        'google_profile_id' => $this->profile->id,
        'email' => 'jane@example.com',
        'text' => 'Jane, thanks for having us over today!',
    ])->assertSessionHasNoErrors();

    $message = inCompany($this->company, fn () => Message::query()->sole());
    expect($message->body)->toBe("Jane, thanks for having us over today!\nhttps://g.page/r/surrey/review")
        ->and($message->service_job_id)->toBe($invoice->service_job_id);

    // Kept as written when the link is still in the text.
    $this->post(route('invoices.review-request', $invoice), [
        'google_profile_id' => $this->profile->id,
        'email' => 'jane@example.com',
        'text' => 'Review us: https://g.page/r/surrey/review please',
    ])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Message::query()->latest('id')->first()->body))
        ->toBe('Review us: https://g.page/r/surrey/review please');

    // The next customer gets the template text again.
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('reviewPrompt.profiles.0.text', fn (string $text) => ! str_contains($text, 'thanks for having us')));
});
