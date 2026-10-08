<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create();
    $property = Property::factory()->for($this->customer)->create();
    $this->paidJob = ServiceJob::factory()->for($property)->withVisit($this->tech)
        ->create(['brand_id' => $brand->id, 'tech_notes' => 'Replaced the drain pump']);
    $this->owingJob = ServiceJob::factory()->for($property)->withVisit($this->owner)->create(['brand_id' => $brand->id]);
    $this->openJob = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id, 'description' => 'Fridge not cooling']);

    $this->actingAs($this->owner);
    // 280.50 each (documentPayload): one paid in full, one paid 100.
    $this->post(route('invoices.store', $this->paidJob), documentPayload());
    $this->post(route('invoices.store', $this->owingJob), documentPayload());
    [$paid, $owing] = inCompany($this->company, fn () => Invoice::orderBy('id')->get()->all());
    $this->post(route('payments.store', $paid), ['amount' => '280.50', 'method' => 'cash']);
    $this->post(route('payments.store', $owing), ['amount' => '100', 'method' => 'cash']);
    $this->paidInvoice = $paid;
});

test('the customer card shows how many jobs, how much was paid and how much is owed', function () {
    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->component('customers/show')
        ->where('summary.jobs', 3)
        ->where('summary.money', [['currency' => 'CAD', 'paid' => 38050, 'owed' => 18050]]));
});

test('the job history lists date, appliance, work done, amount and payment status with links', function () {
    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->has('history', 3)
        ->where('history', function ($rows) {
            $rows = collect($rows)->keyBy('id');

            return $rows[$this->paidJob->id]['payment'] === 'paid'
                && $rows[$this->paidJob->id]['work'] === 'Replaced the drain pump'
                && $rows[$this->paidJob->id]['total'] === 28050
                && $rows[$this->paidJob->id]['invoice_id'] === $this->paidInvoice->id
                && $rows[$this->owingJob->id]['payment'] === 'partial'
                && $rows[$this->openJob->id]['payment'] === null
                && $rows[$this->openJob->id]['work'] === 'Fridge not cooling'
                && $rows[$this->openJob->id]['date'] !== null;
        }));
});

test('a technician sees the totals of their own jobs only', function () {
    $this->actingAs($this->tech)->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('summary.jobs', 1)
        ->where('summary.money', [['currency' => 'CAD', 'paid' => 28050, 'owed' => 0]])
        ->has('history', 1));
});
