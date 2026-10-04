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

    $this->get(route('reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('business.totals', [
                ['currency' => 'CAD', 'invoices' => 2, 'revenue' => 8000, 'average' => 4000],
                ['currency' => 'USD', 'invoices' => 1, 'revenue' => 7000, 'average' => 7000],
            ])
            ->where('business.byBrand.0.name', 'Appliance')
            ->where('business.byTechnician.0.name', 'Tim')
            ->where('business.byJobType.0.name', 'Repair')
            ->where('business.bySource.0.name', 'Website'));
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
    $this->get(route('reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page->where('business.conversion', ['estimates' => 4, 'approved' => 2, 'rate' => 50]));
});

test('reports stay tenant scoped and technicians cannot access them', function () {
    $other = Company::factory()->create();
    inCompany($other, function () use ($other) {
        $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))
            ->create(['brand_id' => Brand::factory()->create(['company_id' => $other->id])->id]);
        Invoice::factory()->create(['currency' => 'CAD', 'service_job_id' => $job->id, 'issued_on' => '2026-10-10', 'total' => 99999]);
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $job->id, 'issued_on' => '2026-10-10', 'status' => 'approved']);
    });
    $this->get(route('reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('business.totals', 0)
            ->where('business.conversion', ['estimates' => 0, 'approved' => 0, 'rate' => null]));
    $this->actingAs($this->tech)->get(route('reports.index'))->assertForbidden();
});

test('reports respect office brand restrictions', function () {
    $restricted = memberOf($this->company, UserRole::Admin);
    $allowed = Brand::factory()->create(['company_id' => $this->company->id]);
    $restricted->brands()->attach($allowed, ['company_id' => $this->company->id]);
    inCompany($this->company, function () {
        Invoice::factory()->create(['currency' => 'CAD', 'service_job_id' => $this->job->id, 'issued_on' => '2026-10-10', 'total' => 99999]);
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $this->job->id, 'issued_on' => '2026-10-10', 'status' => 'approved']);
        $this->job->forceFill(['closed_at' => '2026-10-10 12:00:00'])->save();
    });
    $this->actingAs($restricted)->get(route('reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('business.totals', 0)
            // Costs and profit are for the Owner only.
            ->where('showProfit', false)
            ->where('totals', null)
            ->where('business.conversion.estimates', 0));
});

test('invalid and reversed report dates are rejected', function () {
    $this->get(route('reports.index', ['from' => '2026-02-30']))->assertSessionHasErrors('from');
    $this->get(route('reports.index', ['from' => '2026-10-31', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
});

test('profit never adds invoice amounts from different currencies', function () {
    inCompany($this->company, function () {
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => $this->company->currency, 'total' => 10000, 'tax_total' => 0]);
        Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => 'USD', 'total' => 90000, 'tax_total' => 0]);
        expect(JobProfit::for($this->job)['revenue'])->toBe(10000);
    });
});
