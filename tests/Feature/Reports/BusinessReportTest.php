<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\JobProfit;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tim']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Appliance']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))
        ->withVisit($this->tech)->create(['brand_id' => $this->brand->id, 'lead_source' => 'website']);
    $this->actingAs($this->owner);
});

test('revenue uses invoice dates, excludes tax and void invoices, and separates currencies', function () {
    inCompany($this->company, function () {
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => 'CAD', 'issued_on' => '2026-10-01', 'total' => 11000, 'tax_total' => 1000, 'credited_amount' => 5500]);
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => 'CAD', 'issued_on' => '2026-10-31', 'total' => 3000, 'tax_total' => 0]);
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => 'USD', 'issued_on' => '2026-10-10', 'total' => 7000, 'tax_total' => 0]);
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => 'CAD', 'issued_on' => '2026-10-10', 'total' => 99999, 'status' => 'void']);
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => 'CAD', 'issued_on' => '2026-11-01', 'total' => 99999]);
    });

    $this->get(route('statistics.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.business.totals', [
                ['currency' => 'CAD', 'invoices' => 2, 'revenue' => 8000, 'average' => 4000],
                ['currency' => 'USD', 'invoices' => 1, 'revenue' => 7000, 'average' => 7000],
            ])
            ->where('reports.business.byBrand.0.name', 'Appliance')
            ->where('reports.business.byTechnician.0.name', 'Tim')
            ->where('reports.business.byJobType.0.name', 'Repair'));
});

test('conversion excludes unsent drafts and old revisions and counts approved or invoiced estimates', function () {
    inCompany($this->company, function () {
        foreach (['approved', 'invoiced', 'declined'] as $status) {
            Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $this->job->id, 'status' => $status, 'issued_on' => '2026-10-10']);
        }
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $this->job->id, 'issued_on' => '2026-10-10', 'sent_at' => '2026-10-10 12:00:00']);
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $this->job->id, 'issued_on' => '2026-10-10']);
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $this->job->id, 'issued_on' => '2026-10-10', 'status' => 'revised', 'revised_at' => now(), 'sent_at' => now()]);
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $this->job->id, 'issued_on' => '2026-09-30', 'status' => 'approved']);
    });
    $this->get(route('statistics.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page->where('reports.business.conversion', ['estimates' => 4, 'approved' => 2, 'rate' => 50]));
});

test('reports stay tenant scoped and technicians cannot access them', function () {
    $other = Company::factory()->create();
    inCompany($other, function () use ($other) {
        $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))
            ->create(['brand_id' => Brand::factory()->create(['company_id' => $other->id])->id]);
        Invoice::factory()->create(['currency' => 'CAD', 'service_job_id' => $job->id, 'issued_on' => '2026-10-10', 'total' => 99999]);
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $job->id, 'issued_on' => '2026-10-10', 'status' => 'approved']);
    });
    $this->get(route('statistics.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports.business.totals', 0)
            ->where('reports.business.conversion', ['estimates' => 0, 'approved' => 0, 'rate' => null]));
    $this->actingAs($this->tech)->get(route('statistics.index'))->assertForbidden();
});

test('statistics are for the Owner only: an Admin is refused', function () {
    $this->actingAs(memberOf($this->company, UserRole::Admin))->get(route('statistics.index'))->assertForbidden();
    // The old Reports address opens Statistics.
    $this->actingAs($this->owner)->get(route('reports.index'))->assertRedirect(route('statistics.index'));
});

test('invalid and reversed report dates are rejected', function () {
    $this->get(route('statistics.index', ['from' => '2026-02-30']))->assertSessionHasErrors('from');
    $this->get(route('statistics.index', ['from' => '2026-10-31', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
});

test('profit never adds invoice amounts from different currencies', function () {
    inCompany($this->company, function () {
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => $this->company->currency, 'total' => 10000, 'tax_total' => 0]);
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => 'USD', 'total' => 90000, 'tax_total' => 0]);
        expect(JobProfit::for($this->job)['revenue'])->toBe(10000);
    });
});
