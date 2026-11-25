<?php

use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Closed jobs off Today (owner's task of November 25): "Close job" writes Completed into the job (then Paid / Awaiting
 * payment by its invoices); closed jobs leave the day and show under Completed; a job whose invoices are all paid
 * closes by itself; jobs:close-finished fixes the jobs closed or paid earlier whose status stayed open.
 */

beforeEach(function () {
    Mail::fake();
    // 11:00 in Vancouver.
    $this->travelTo('2031-03-10 18:00:00');
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->withEmail('jane@example.com')->create();
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    // This morning's visit, not marked as started.
    $this->visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->owner)
        ->create(['scheduled_start' => now()->subHours(2), 'scheduled_end' => now()->subHour()]);
    $this->actingAs($this->owner);
});

function dayVisits(): array
{
    $ids = [];
    test()->get(route('jobs.mine'))->assertInertia(function (Assert $page) use (&$ids) {
        $ids = collect($page->toArray()['props']['visits'])->pluck('job.id')->all();
    });

    return $ids;
}

function completedVisits(): array
{
    $ids = [];
    test()->get(route('jobs.mine', ['tab' => 'completed']))->assertInertia(function (Assert $page) use (&$ids) {
        $ids = collect($page->toArray()['props']['visits'])->pluck('job.id')->all();
    });

    return $ids;
}

test('closing a job writes Completed into the job; it leaves Today and shows under Completed', function () {
    expect(dayVisits())->toBe([$this->job->id])->and(completedVisits())->toBe([]);

    $this->post(route('jobs.close', $this->job), ['outcome' => 'repaired'])->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    expect($job->status)->toBe(JobStatus::Completed)
        ->and($job->outcome)->toBe(JobOutcome::Repaired)
        ->and($job->closed_at)->not->toBeNull()
        // Today's visit is done with the job, not called off.
        ->and($this->visit->fresh()->status)->toBe(VisitStatus::Completed);

    expect(dayVisits())->toBe([])->and(completedVisits())->toBe([$this->job->id]);
    // Off the open jobs list too.
    $this->get(route('jobs.index'))->assertInertia(fn (Assert $page) => $page->where('jobs', fn ($jobs) => collect($jobs)->pluck('id')->doesntContain($this->job->id)));
});

test('a visit booked for a later day is called off when the job is closed', function () {
    $later = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->owner)
        ->create(['scheduled_start' => now()->addDays(2), 'scheduled_end' => now()->addDays(2)->addHour()]);

    $this->post(route('jobs.close', $this->job), ['outcome' => 'repaired'])->assertSessionHasNoErrors();

    expect($later->fresh()->status)->toBe(VisitStatus::Cancelled);
});

test('test job: invoice, payment on site, close: off Today, under Completed', function () {
    $this->visit->forceFill(['status' => VisitStatus::InProgress, 'started_at' => now()])->saveQuietly();
    $this->job->forceFill(['status' => JobStatus::InProgress])->saveQuietly();

    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();

    // Paid while the technician is still on site: the job waits for Finish / Close (a part may still be needed).
    expect($this->job->fresh()->status)->toBe(JobStatus::InProgress)->and(dayVisits())->toBe([$this->job->id]);

    $this->post(route('jobs.close', $this->job), ['outcome' => 'repaired'])->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    expect($job->status)->toBe(JobStatus::Paid)->and($job->closed_at)->not->toBeNull();
    expect(dayVisits())->toBe([])->and(completedVisits())->toBe([$this->job->id]);
});

test('an invoice paid in full closes the job by itself', function (string $method) {
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());

    $this->post(route('payments.store', $invoice), ['amount' => '100.00', 'method' => $method])->assertSessionHasNoErrors();
    expect($this->job->fresh())->outcome->toBeNull()->status->toBe(JobStatus::Scheduled);

    $this->post(route('payments.store', $invoice), ['amount' => '180.50', 'method' => $method])->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    expect($job->status)->toBe(JobStatus::Paid)
        ->and($job->outcome)->toBe(JobOutcome::Repaired)
        ->and($job->closed_at)->not->toBeNull()
        ->and($this->visit->fresh()->status)->toBe(VisitStatus::Completed);
    expect(dayVisits())->toBe([])->and(completedVisits())->toBe([$this->job->id]);
})->with(['cash' => 'cash', 'e-transfer' => 'bank_transfer', 'card' => 'card_terminal']);

test('a paid invoice does not close a job waiting for parts or with a visit booked ahead', function (string $case) {
    if ($case === 'parts') {
        $this->job->forceFill(['status' => JobStatus::WaitingForParts])->saveQuietly();
    } else {
        $this->visit->forceFill(['scheduled_start' => now()->addDays(3), 'scheduled_end' => now()->addDays(3)->addHour()])->saveQuietly();
    }

    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();

    expect($this->job->fresh())->outcome->toBeNull()->closed_at->toBeNull();
})->with(['parts', 'visit ahead']);

test('Finish on site with a paid invoice keeps the outcome the technician picked', function () {
    $this->visit->forceFill(['status' => VisitStatus::InProgress, 'started_at' => now()])->saveQuietly();
    $this->job->forceFill(['status' => JobStatus::InProgress])->saveQuietly();
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();

    $reason = inCompany($this->company, fn () => currentCompany()->closureReasons(JobOutcome::CustomerDeclined)[0]);
    $this->post(route('visits.finish', $this->visit), ['outcome' => 'customer_declined', 'reason' => $reason])->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    expect($job->outcome)->toBe(JobOutcome::CustomerDeclined)->and($job->status)->toBe(JobStatus::Paid);
});

test('jobs:close-finished moves closed and fully paid jobs that still look open, in every company', function () {
    // Closed earlier, status left open.
    $this->job->forceFill(['outcome' => JobOutcome::Repaired, 'closed_at' => now()->subDay(), 'status' => JobStatus::InProgress])->saveQuietly();

    // Paid in full yesterday, never closed, visit left in progress from yesterday.
    $paid = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::InProgress]);
    $stale = JobVisit::factory()->for($paid, 'job')->assignedTo($this->owner)
        ->create(['scheduled_start' => now()->subDay(), 'scheduled_end' => now()->subDay()->addHour(), 'status' => VisitStatus::InProgress, 'started_at' => now()->subDay()]);
    $this->post(route('invoices.store', $paid), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash'])->assertSessionHasNoErrors();
    expect($paid->fresh()->closed_at)->toBeNull();

    // Waiting for parts with a paid diagnosis: left as it is.
    $parts = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::WaitingForParts]);

    // Another company's closed job: moved in its own company.
    app(CurrentCompany::class)->forget();
    $other = Company::factory()->create();
    $otherJob = inCompany($other, fn () => ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))
        ->create(['brand_id' => Brand::factory()->create(['company_id' => $other->id])->id, 'status' => JobStatus::Scheduled, 'outcome' => JobOutcome::Repaired, 'closed_at' => now()]));
    app(CurrentCompany::class)->forget();

    $this->artisan('jobs:close-finished', ['--dry-run' => true])->expectsOutputToContain('Would move: 3')->assertSuccessful();
    expect($this->job->fresh()->status)->toBe(JobStatus::InProgress);

    $this->artisan('jobs:close-finished')->expectsOutputToContain('Moved: 3')->assertSuccessful();

    expect($this->job->fresh()->status)->toBe(JobStatus::Completed)
        ->and($paid->fresh())->status->toBe(JobStatus::Paid)->outcome->toBe(JobOutcome::Repaired)
        ->and($stale->fresh()->status)->toBe(VisitStatus::Completed)
        ->and($parts->fresh()->status)->toBe(JobStatus::WaitingForParts)
        ->and(inCompany($other, fn () => $otherJob->fresh()->status))->toBe(JobStatus::Completed);

    // Run again: nothing left.
    $this->artisan('jobs:close-finished')->expectsOutputToContain('Moved: 0')->assertSuccessful();
});
