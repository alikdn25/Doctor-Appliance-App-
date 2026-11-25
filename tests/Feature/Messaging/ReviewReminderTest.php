<?php

use App\Enums\UserRole;
use App\Mail\CustomerMessageMail;
use App\Mail\DocumentMail;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Property;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The one Google review reminder (owner's task of November 25): only where a review was requested by hand; 24 hours
 * after the job was paid in full, a separate email with the same text and location, unless the customer clicked a
 * review link of the job in any email. Once; email only. The wait is configurable for tests.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2031-04-02 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex K']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->profile = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Surrey', 'review_url' => 'https://g.page/r/surrey/review']));
    $this->brand->update(['google_profile_id' => $this->profile->id]);
    $customer = Customer::factory()->for($this->company)->withEmail('jane@example.com')->create(['first_name' => 'Jane']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $this->brand->id, 'status' => 'completed']);
    $this->actingAs($this->owner);

    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $this->invoice = inCompany($this->company, fn () => Invoice::sole());
});

/** "Send" in "Send Google Review request?" (the request asked for by hand), then the job paid in full. */
function requestedAndPaid(): void
{
    test()->post(route('invoices.review-request', test()->invoice), ['google_profile_id' => test()->profile->id, 'email' => 'jane@example.com'])
        ->assertSessionHasNoErrors();
    test()->post(route('payments.store', test()->invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();
}

function reviewEmails(): int
{
    return inCompany(test()->company, fn () => Message::query()->where('kind', 'review_request')->where('channel', 'email')->count());
}

test('without a click the one reminder goes 24 hours after payment, by email, with the same text and location', function () {
    requestedAndPaid();
    $first = inCompany($this->company, fn () => Message::query()->sole());

    $this->travel(23)->hours();
    $this->artisan('reviews:send-reminders')->assertSuccessful();
    expect(reviewEmails())->toBe(1);

    $this->travel(2)->hours();
    $this->artisan('reviews:send-reminders')->assertSuccessful();
    $reminder = inCompany($this->company, fn () => Message::query()->latest('id')->first());

    expect(reviewEmails())->toBe(2)
        ->and($reminder->id)->not->toBe($first->id)
        ->and($reminder)->to->toBe('jane@example.com')->channel->toBe('email')->service_job_id->toBe($this->job->id)
        ->and($reminder->body)->toBe($first->body)
        ->and($reminder->body)->toContain(route('reviews.go', ['token' => $this->job->fresh()->review_token, 'profile' => $this->profile->id]));
    Mail::assertQueued(CustomerMessageMail::class, 2);

    // Only once.
    $this->travel(3)->days();
    $this->artisan('reviews:send-reminders');
    expect(reviewEmails())->toBe(2);

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('job.review.requested', true)
        ->where('job.review.clicked_at', null)
        ->where('job.review.reminder_sent_at', fn ($at) => $at !== null));
});

test('a click on a review link in any email stops the reminder; the click is recorded and opens Google', function () {
    requestedAndPaid();
    $link = route('reviews.go', ['token' => $this->job->fresh()->review_token, 'profile' => $this->profile->id]);

    // Someone signed in (checking the link) is not counted.
    $this->get($link)->assertRedirect('https://g.page/r/surrey/review');
    expect($this->job->fresh()->review_clicked_at)->toBeNull();

    auth()->logout();
    $this->get($link)->assertRedirect('https://g.page/r/surrey/review');
    expect($this->job->fresh()->review_clicked_at)->not->toBeNull();

    $this->travel(2)->days();
    $this->artisan('reviews:send-reminders');
    expect(reviewEmails())->toBe(1);

    $this->actingAs($this->owner);
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('job.review.clicked_at', fn ($at) => $at !== null)
        ->where('job.review.reminder_sent_at', null));
});

test('the review link of the invoice email counts too', function () {
    $this->post(route('invoices.send', $this->invoice), ['email' => 'jane@example.com', 'message' => 'Hi'])->assertSessionHasNoErrors();
    Mail::assertQueued(DocumentMail::class, fn ($mail) => str_contains($mail->render(), '/r/'));
    $token = $this->job->fresh()->review_token;
    requestedAndPaid();

    auth()->logout();
    $this->get(route('reviews.go', ['token' => $token, 'profile' => $this->profile->id]))->assertRedirect();

    $this->travel(2)->days();
    $this->artisan('reviews:send-reminders');
    expect(reviewEmails())->toBe(1);
});

test('no reminder where no review was requested by hand, or before the job is paid', function () {
    $this->post(route('payments.store', $this->invoice), ['amount' => '280.50', 'method' => 'cash']);
    $this->travel(2)->days();
    $this->artisan('reviews:send-reminders');
    expect(reviewEmails())->toBe(0);

    // Requested, but not paid in full.
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $unpaid = inCompany($this->company, fn () => Invoice::latest('id')->first());
    $this->post(route('invoices.review-request', $unpaid), ['google_profile_id' => $this->profile->id, 'email' => 'jane@example.com']);
    $this->travel(2)->days();
    $this->artisan('reviews:send-reminders');
    expect(reviewEmails())->toBe(1)
        ->and(inCompany($this->company, fn () => ReviewRequest::sole()->reminder_sent_at))->toBeNull();
});

test('the wait can be shortened for a test', function () {
    config(['fieldservice.review_reminder_minutes' => 1]);
    requestedAndPaid();

    $this->travel(2)->minutes();
    $this->artisan('reviews:send-reminders');

    expect(reviewEmails())->toBe(2);
});

test('a review link of another company or an unknown token opens nothing', function () {
    requestedAndPaid();
    $token = $this->job->fresh()->review_token;
    app(CurrentCompany::class)->forget();
    $foreign = inCompany(Company::factory()->create(), fn () => GoogleProfile::create(['label' => 'X', 'review_url' => 'https://g.page/r/x/review']));
    auth()->logout();

    $this->get(route('reviews.go', ['token' => $token, 'profile' => $foreign->id]))->assertNotFound();
    $this->get(route('reviews.go', ['token' => str_repeat('a', 40), 'profile' => $this->profile->id]))->assertNotFound();
    expect($this->job->fresh()->review_clicked_at)->toBeNull();
});
