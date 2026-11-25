<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\PublicDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Awaiting payment (owner's task of November 25): a sent invoice puts the job in "Awaiting payment" (off Today and
 * the schedule, in the orange counter and its list); the invoice link /i/{token} records when it was opened; paying
 * every sent invoice in full closes the job as Paid by itself.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2031-03-10 18:00:00');
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->withEmail('jane@example.com')->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $brand->id, 'status' => JobStatus::Scheduled]);
    $this->visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->owner)
        ->create(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
    $this->actingAs($this->owner);

    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $this->invoice = inCompany($this->company, fn () => Invoice::sole());
});

function sendInvoice(Invoice $invoice): void
{
    test()->post(route('invoices.send', $invoice), ['email' => 'jane@example.com', 'message' => 'Hi Jane'])->assertSessionHasNoErrors();
}

test('a sent invoice moves the job to Awaiting payment: off Today and the schedule, in the counter and its list', function () {
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->has('visits', 1)
        ->where('unfinishedJobs.counts.awaiting_payment', 0));

    sendInvoice($this->invoice);

    expect($this->job->fresh()->status)->toBe(JobStatus::AwaitingPayment);

    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->has('visits', 0)
        ->where('unfinishedJobs.counts.awaiting_payment', 1));
    $this->get(route('calendar'))->assertInertia(fn (Assert $page) => $page->has('visits', 0));

    $this->travel(5)->days();
    $this->get(route('invoices.awaiting'))->assertInertia(fn (Assert $page) => $page
        ->component('invoices/awaiting-payment')
        ->where('jobs', 1)
        ->has('rows', 1)
        ->where('rows.0.job_id', $this->job->id)
        ->where('rows.0.number', $this->invoice->number)
        ->where('rows.0.customer', 'Jane Doe')
        ->where('rows.0.balance', '$280.50')
        ->where('rows.0.days', 5)
        ->where('rows.0.state', 'sent')
        ->where('rows.0.opened_at', null));
});

test('sending an estimate does not change the job; it stays on Today', function () {
    $this->post(route('estimates.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $estimate = inCompany($this->company, fn () => Estimate::sole());

    $this->post(route('estimates.send', $estimate), ['email' => 'jane@example.com', 'message' => 'Hi'])->assertSessionHasNoErrors();

    expect($this->job->fresh()->status)->toBe(JobStatus::Scheduled);
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->has('visits', 1)->where('unfinishedJobs.counts.awaiting_payment', 0));
});

test('the invoice link records the first and the last open, then opens the invoice page', function () {
    sendInvoice($this->invoice);
    $token = $this->invoice->fresh()->public_token;
    $link = PublicDocument::url($this->invoice->fresh());
    expect($link)->toBe(route('invoices.open', $token));

    // People signed in to the app (checking the link) are not counted.
    $this->get($link)->assertRedirect(route('documents.public', $token));
    expect($this->invoice->fresh()->first_opened_at)->toBeNull();

    auth()->logout();
    $this->get($link)->assertRedirect(route('documents.public', $token));
    $first = $this->invoice->fresh()->first_opened_at;
    expect($first)->not->toBeNull();

    $this->travel(2)->hours();
    $this->get($link)->assertRedirect();
    $invoice = $this->invoice->fresh();
    expect($invoice->first_opened_at->equalTo($first))->toBeTrue()
        ->and($invoice->last_opened_at->greaterThan($first))->toBeTrue();

    $this->actingAs($this->owner);
    $this->get(route('invoices.awaiting'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.state', 'opened')
        ->where('rows.0.opened_at', $first->toIso8601String()));
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('job.invoices.0.opened_at', $first->toIso8601String()));

    // An unknown token or an estimate's token is not an invoice link.
    $this->get(route('invoices.open', 'i'.str_repeat('x', 47)))->assertNotFound();
});

test('paying in full closes the job as Paid by itself; it leaves the counter and the list', function (string $method) {
    sendInvoice($this->invoice);

    $this->post(route('payments.store', $this->invoice), ['amount' => '100.00', 'method' => $method])->assertSessionHasNoErrors();
    expect($this->job->fresh()->status)->toBe(JobStatus::AwaitingPayment);

    $this->post(route('payments.store', $this->invoice), ['amount' => '180.50', 'method' => $method])->assertSessionHasNoErrors();
    expect($this->job->fresh()->status)->toBe(JobStatus::Paid);

    $this->get(route('invoices.awaiting'))->assertInertia(fn (Assert $page) => $page->has('rows', 0)->where('jobs', 0)
        ->where('unfinishedJobs.counts.awaiting_payment', 0));
})->with(['cash' => 'cash', 'e-transfer' => 'bank_transfer', 'card' => 'card_terminal']);

test('the job waits until every sent invoice is paid in full', function () {
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $second = inCompany($this->company, fn () => Invoice::latest('id')->first());
    sendInvoice($this->invoice);
    sendInvoice($second);

    $this->post(route('payments.store', $this->invoice), ['amount' => '280.50', 'method' => 'cash']);
    expect($this->job->fresh()->status)->toBe(JobStatus::AwaitingPayment);
    $this->get(route('invoices.awaiting'))->assertInertia(fn (Assert $page) => $page
        ->has('rows', 2)->where('rows.0.state', 'paid')->where('rows.1.state', 'sent'));

    $this->post(route('payments.store', $second), ['amount' => '280.50', 'method' => 'cash']);
    expect($this->job->fresh()->status)->toBe(JobStatus::Paid);
});

test('the visit under way is finished when the invoice is sent', function () {
    $this->visit->forceFill(['status' => VisitStatus::InProgress, 'started_at' => now()])->saveQuietly();
    $this->job->forceFill(['status' => JobStatus::InProgress])->saveQuietly();

    sendInvoice($this->invoice);

    expect($this->visit->fresh())->status->toBe(VisitStatus::Completed)->finished_at->not->toBeNull()
        ->and($this->job->fresh())->status->toBe(JobStatus::AwaitingPayment)->completed_at->not->toBeNull();
});

test('a diagnosis invoice sent while the job waits for parts keeps the parts status', function () {
    $this->job->forceFill(['status' => JobStatus::WaitingForParts])->saveQuietly();

    sendInvoice($this->invoice);

    expect($this->job->fresh()->status)->toBe(JobStatus::WaitingForParts);
});

test('a voided sent invoice takes the job out of Awaiting payment', function () {
    sendInvoice($this->invoice);
    $this->post(route('invoices.void', $this->invoice), ['reason' => 'Wrong amount'])->assertSessionHasNoErrors();

    expect($this->job->fresh()->status)->not->toBe(JobStatus::AwaitingPayment);
});

test('the counter and the list are per company and need invoice access', function () {
    sendInvoice($this->invoice);
    $tech = memberOf($this->company, UserRole::Technician);

    app(CurrentCompany::class)->forget();
    $other = Company::factory()->create();
    $this->actingAs(memberOf($other, UserRole::Owner));
    $this->get(route('invoices.awaiting'))->assertInertia(fn (Assert $page) => $page->has('rows', 0)
        ->where('unfinishedJobs.counts.awaiting_payment', 0));

    app(CurrentCompany::class)->forget();
    $this->actingAs($tech);
    $this->get(route('invoices.awaiting'))->assertForbidden();
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs.counts.awaiting_payment', null));
});
