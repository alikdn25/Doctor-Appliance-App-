<?php

use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Admin));

    $this->jane = Customer::factory()->for($this->company)->withPhone('(604) 555-0142')->withEmail('jane@example.com')
        ->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $property = Property::factory()->for($this->jane)->create(['line1' => '8450 128 St', 'city' => 'Surrey', 'postal_code' => 'V3W 4G1']);
    Appliance::factory()->for($property)->create(['model_number' => 'WM3900HWA', 'serial_number' => '912KWPX4B123']);

    $this->bob = Customer::factory()->for($this->company)->withPhone('778-555-0199')
        ->create(['first_name' => 'Bob', 'last_name' => 'Fox']);
    Property::factory()->for($this->bob)->create(['line1' => '6200 McKay Ave', 'city' => 'Burnaby', 'postal_code' => 'V5H 2K2']);
});

function searchCustomers(string $term): array
{
    $ids = [];

    test()->get(route('customers.index', ['search' => $term]))
        ->assertInertia(function (Assert $page) use (&$ids) {
            $ids = collect($page->toArray()['props']['customers']['data'])->pluck('id')->all();
        });

    return $ids;
}

test('customers can be found by', function (string $term) {
    expect(searchCustomers($term))->toBe([$this->jane->id]);
})->with([
    'first name' => ['jane'],
    'last name' => ['COOP'],
    'phone with formatting' => ['604-555-0142'],
    'phone digits' => ['6045550142'],
    'partial phone' => ['0142'],
    'email' => ['jane@example'],
    'street' => ['128 st'],
    'city' => ['surrey'],
    'postal code' => ['V3W'],
    'model number' => ['wm3900'],
    'serial number' => ['KWPX4B'],
]);

test('an empty search returns everyone', function () {
    expect(searchCustomers(''))->toHaveCount(2);
});

test('search wildcards are treated literally', function () {
    expect(searchCustomers('%'))->toBe([]);
});

test('deleted appliances do not match the search', function () {
    inCompany($this->company, fn () => Appliance::query()->delete());

    expect(searchCustomers('wm3900'))->toBe([]);
});
