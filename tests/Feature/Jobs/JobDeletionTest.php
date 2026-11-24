<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobCostItem;
use App\Models\JobPart;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\Trip;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Deleting a job (owner's task of November 24): any status, one confirm, no reason; everything tied to it goes to
 * the trash with it and comes back with it.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);

    $this->actingAs($this->owner);
});

test('a job in any status is deleted with one request', function (JobStatus $status) {
    inCompany($this->company, fn () => $this->job->forceFill(['status' => $status])->save());

    $this->delete(route('jobs.destroy', $this->job))
        ->assertRedirect(route('jobs.index'))
        ->assertSessionHasNoErrors();

    expect(ServiceJob::withoutCompanyScope()->withTrashed()->find($this->job->id)->trashed())->toBeTrue();
})->with(JobStatus::cases());

test('a job with an estimate, an invoice, a payment, costs, parts and a trip goes to the trash whole and comes back whole', function () {
    $this->post(route('estimates.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->post(route('payments.store', $invoice), ['amount' => '100', 'method' => 'cash'])->assertSessionHasNoErrors();

    inCompany($this->company, function () {
        $cost = new JobCostItem(['description' => 'Pump', 'unit_cost' => 4000, 'quantity' => 1]);
        $cost->service_job_id = $this->job->id;
        $cost->currency = $this->company->currency;
        $cost->save();
        JobPart::create(['service_job_id' => $this->job->id, 'name' => 'Drain pump']);
        Trip::create(['user_id' => $this->tech->id, 'trip_date' => now()->toDateString(), 'type' => 'client', 'distance_km' => 12, 'service_job_id' => $this->job->id]);
    });
    // My expenses of the job (business expenses linked to it).
    $this->post(route('jobs.my-expenses.store', $this->job), ['lines' => [['description' => 'Pump', 'amount' => '60']]])->assertSessionHasNoErrors();

    $this->delete(route('jobs.destroy', $this->job))->assertRedirect(route('jobs.index'));

    inCompany($this->company, function () {
        expect(ServiceJob::find($this->job->id))->toBeNull()
            ->and(Invoice::count())->toBe(0)
            ->and(Estimate::count())->toBe(0)
            ->and(Payment::count())->toBe(0)
            ->and(JobCostItem::count())->toBe(0)
            ->and(JobPart::count())->toBe(0)
            ->and(Trip::count())->toBe(0)
            ->and(BusinessExpense::count())->toBe(0)
            ->and(Invoice::onlyTrashed()->count())->toBe(1)
            ->and(Payment::onlyTrashed()->count())->toBe(1);
    });

    // The trash of documents does not list them separately: they come back with the job.
    $this->get(route('trash.index'))->assertInertia(fn (Assert $page) => $page->has('items', 0));
    $this->get(route('jobs.index', ['tab' => 'deleted']))->assertOk();

    $this->post(route('jobs.restore', $this->job))->assertRedirect(route('jobs.show', $this->job));

    inCompany($this->company, function () use ($invoice) {
        expect(ServiceJob::find($this->job->id))->not->toBeNull()
            ->and(Invoice::count())->toBe(1)
            ->and(Estimate::count())->toBe(1)
            ->and(Payment::count())->toBe(1)
            ->and(JobCostItem::count())->toBe(1)
            ->and(JobPart::count())->toBe(1)
            ->and(Trip::count())->toBe(1)
            ->and(BusinessExpense::count())->toBe(1)
            ->and(Invoice::find($invoice->id)->amount_paid)->toBe(10000);
    });
});

test('a record deleted on its own before the job stays deleted when the job is restored', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $this->post(route('invoices.store', $this->job), documentPayload());
    $first = inCompany($this->company, fn () => Invoice::orderBy('id')->first());
    inCompany($this->company, fn () => Invoice::withoutEvents(fn () => $first->forceFill(['deleted_at' => now()->subDay()])->save()));

    $this->delete(route('jobs.destroy', $this->job));
    $this->post(route('jobs.restore', $this->job));

    inCompany($this->company, fn () => expect(Invoice::pluck('id')->all())->not->toContain($first->id)
        ->and(Invoice::count())->toBe(1));
});

test('deleted jobs are not in the not-completed strip', function () {
    $this->get(route('jobs.index'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs.total', 1));

    $this->delete(route('jobs.destroy', $this->job));

    $this->get(route('jobs.index'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs.total', 0));
});

test('another company cannot delete or restore the job', function () {
    $other = Company::factory()->create();
    $this->actingAs(memberOf($other));

    $this->delete(route('jobs.destroy', $this->job))->assertNotFound();
    expect(ServiceJob::withoutCompanyScope()->find($this->job->id))->not->toBeNull();
});
