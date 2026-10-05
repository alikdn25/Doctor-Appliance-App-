<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobCostItem;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\MoneyInput;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency' => 'CAD']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))->create(['brand_id' => $brand->id]);
    $this->actingAs($this->owner);
});

test('a cost typed with a currency sign is accepted', function () {
    $this->post(route('jobs.costs.store', $this->job), ['kind' => 'part', 'description' => 'Fuse', 'quantity' => '1', 'unit_cost' => '$15.5'])
        ->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => JobCostItem::query()->sole()->unit_cost))->toBe(1550);
});

test('each missing field gets its own plain message', function () {
    $this->post(route('jobs.costs.store', $this->job), ['kind' => 'part', 'description' => '', 'quantity' => '1', 'unit_cost' => 'abc'])
        ->assertSessionHasErrors([
            'description' => __('costs.errors.description'),
            'unit_cost' => __('validation.money_amount', ['example' => '15.55']),
        ]);
});

test('a cost line can be corrected', function () {
    $this->post(route('jobs.costs.store', $this->job), ['kind' => 'part', 'description' => 'Fuse', 'quantity' => '1', 'unit_cost' => '12']);
    $item = inCompany($this->company, fn () => JobCostItem::query()->sole());

    $this->put(route('jobs.costs.update', [$this->job, $item]), ['kind' => 'part', 'description' => 'Thermal fuse', 'quantity' => '2', 'unit_cost' => '14.00'])
        ->assertSessionHasNoErrors();

    $item = inCompany($this->company, fn () => $item->fresh());
    expect($item->description)->toBe('Thermal fuse')->and($item->unit_cost)->toBe(1400)->and($item->totalCost())->toBe(2800);
});

test('a cost line of another company cannot be changed', function () {
    $other = Company::factory()->create();
    $brand = Brand::factory()->create(['company_id' => $other->id]);
    $otherJob = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))->create(['brand_id' => $brand->id]);
    $this->actingAs(memberOf($other, UserRole::Owner))
        ->post(route('jobs.costs.store', $otherJob), ['kind' => 'part', 'description' => 'Fuse', 'quantity' => '1', 'unit_cost' => '12']);
    $item = inCompany($other, fn () => JobCostItem::query()->sole());

    $this->actingAs($this->owner)
        ->put(route('jobs.costs.update', [$otherJob, $item]), ['kind' => 'part', 'description' => 'X', 'quantity' => '1', 'unit_cost' => '1'])
        ->assertNotFound();
});

test('typed amounts are cleaned before validation', function (string $typed, string $clean) {
    expect(MoneyInput::clean($typed))->toBe($clean);
})->with([
    ['$15.5', '15.5'],
    ['CA$ 1,250.00', '1250.00'],
    ['15,50', '15.50'],
    [' 95 ', '95'],
    ['abc', 'abc'],
]);
