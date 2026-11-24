<?php

use App\Enums\UserRole;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver', 'currency' => 'CAD', 'mileage_rate' => 50]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->admin = memberOf($this->company, UserRole::Admin);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($property)->withVisit($this->tech)->create();

    // Labor 200 + part 100 (cost 40, typed privately by the technician) + material 10 with no cost.
    $this->actingAs($this->tech)->post(route('invoices.store', $this->job), documentPayload(['items' => [
        ['description' => 'Labor', 'quantity' => '1', 'unit_price' => '200', 'taxable' => true],
        ['description' => 'Drain pump', 'kind' => 'part', 'quantity' => '1', 'unit_price' => '100', 'unit_cost' => '40', 'taxable' => true],
        ['description' => 'Hose clamp', 'kind' => 'material', 'quantity' => '1', 'unit_price' => '10', 'taxable' => true],
    ]]))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('jobs.close', $this->job), ['outcome' => 'repaired']);

    inCompany($this->company, function () {
        $gst = TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_recoverable' => true]);
        $pst = TaxRate::create(['name' => 'PST', 'rate' => 7, 'is_recoverable' => false]);
        $category = BusinessExpenseCategory::query()->forceCreate(['name' => 'Fuel', 'created_by' => $this->owner->id]);
        BusinessExpense::query()->forceCreate([
            'category_id' => $category->id, 'spent_on' => '2026-10-10', 'description' => 'Tools', 'amount' => 5000, 'tax_amount' => 600,
            'taxes' => [['tax_rate_id' => $gst->id, 'name' => 'GST', 'rate' => '5', 'amount' => 250], ['tax_rate_id' => $pst->id, 'name' => 'PST', 'rate' => '7', 'amount' => 350]],
            'currency' => 'CAD', 'created_by' => $this->owner->id,
        ]);
        // Outside the period: not counted.
        BusinessExpense::query()->forceCreate([
            'category_id' => $category->id, 'spent_on' => '2026-09-10', 'description' => 'Old', 'amount' => 99999, 'tax_amount' => 0,
            'currency' => 'CAD', 'created_by' => $this->owner->id,
        ]);
        Trip::create(['user_id' => $this->tech->id, 'trip_date' => '2026-10-14', 'type' => 'client', 'distance_km' => 20]);
    });
});

test('profit = revenue without taxes − parts and materials − expenses − mileage, with the margin', function () {
    $this->actingAs($this->owner)->get(route('statistics.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.profit.revenue', 31000)
            // The technician's private part cost is in the Owner's total.
            ->where('reports.profit.cost', 4000)
            // 50.00 + PST 3.50; the recoverable GST is not a cost.
            ->where('reports.profit.expenses', 5350)
            ->where('reports.profit.mileage.distance', 20)
            ->where('reports.profit.mileage.amount', 1000)
            ->where('reports.profit.profit', 31000 - 4000 - 5350 - 1000)
            ->where('reports.profit.margin', 66.6)
            ->where('today', '2026-10-14'));
});

test('parts and materials without a cost count as 0 and are listed', function () {
    $this->actingAs($this->owner)->get(route('statistics.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports.profit.missing_costs', 1)
            ->where('reports.profit.missing_costs.0.description', 'Hose clamp')
            ->where('reports.profit.missing_costs.0.kind', 'material'));
});

test('an Admin cannot open the profit (Statistics is the Owner\'s)', function () {
    $this->actingAs($this->admin)->get(route('statistics.index'))->assertForbidden();
});

test('a day with nothing closed shows no revenue', function () {
    $this->actingAs($this->owner)->get(route('statistics.index', ['from' => '2026-10-13', 'to' => '2026-10-13']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.profit.jobs', 0)
            ->where('reports.profit.revenue', 0)
            ->where('reports.profit.expenses', 0)
            ->where('reports.profit.mileage.distance', 0));
});
