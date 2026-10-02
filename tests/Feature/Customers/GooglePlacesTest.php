<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Google Places address suggestions (SPEC §6): the browser picks the address; the place ID and coordinates are
 * saved with it. The key comes only from .env; without it addresses are typed by hand.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Admin));
    $this->customer = Customer::factory()->for($this->company)->create();
});

function placesAddress(array $overrides = []): array
{
    return array_replace([
        'line1' => '4120 Main St',
        'city' => 'Vancouver',
        'region' => 'BC',
        'postal_code' => 'V5V 3P6',
        'country' => 'CA',
        'google_place_id' => 'ChIJs0-pQ_FzhlQRi_OBm-qWkbs',
        'latitude' => '49.2464530',
        'longitude' => '-123.1009810',
    ], $overrides);
}

test('the browser key is shared only when it is set in the environment', function () {
    config(['services.google_maps.browser_key' => null]);
    $this->get(route('customers.create'))->assertInertia(fn (Assert $page) => $page->where('auth.company.google_maps_key', null));

    config(['services.google_maps.browser_key' => 'AIza-test-key']);
    $this->get(route('customers.create'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.company.google_maps_key', 'AIza-test-key')
        // Suggestions are limited to the company's country.
        ->where('auth.company.country', 'CA'));
});

test('a picked address keeps its place ID and coordinates', function () {
    $this->post(route('properties.store', $this->customer), placesAddress())->assertSessionHasNoErrors();

    $property = inCompany($this->company, fn () => Property::sole());
    expect($property->google_place_id)->toBe('ChIJs0-pQ_FzhlQRi_OBm-qWkbs')
        ->and((float) $property->latitude)->toBe(49.246453)
        ->and((float) $property->longitude)->toBe(-123.100981);

    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('customer.properties.0.google_place_id', 'ChIJs0-pQ_FzhlQRi_OBm-qWkbs'));
});

test('a typed address is saved without them, and editing by hand clears them', function () {
    $this->post(route('properties.store', $this->customer), placesAddress())->assertSessionHasNoErrors();
    $property = inCompany($this->company, fn () => Property::sole());

    // The form empties the place fields when the address is typed over.
    $this->put(route('properties.update', $property), placesAddress([
        'line1' => '4122 Main St', 'google_place_id' => '', 'latitude' => '', 'longitude' => '',
    ]))->assertSessionHasNoErrors();

    $property = inCompany($this->company, fn () => $property->fresh());
    expect($property->line1)->toBe('4122 Main St')
        ->and($property->google_place_id)->toBeNull()
        ->and($property->latitude)->toBeNull()
        ->and($property->longitude)->toBeNull();
});

test('coordinates must be valid and come in pairs', function () {
    $this->post(route('properties.store', $this->customer), placesAddress(['latitude' => '91']))->assertSessionHasErrors('latitude');
    $this->post(route('properties.store', $this->customer), placesAddress(['longitude' => '']))->assertSessionHasErrors('longitude');
    $this->post(route('properties.store', $this->customer), placesAddress(['longitude' => 'east']))->assertSessionHasErrors('longitude');

    expect(inCompany($this->company, fn () => Property::count()))->toBe(0);
});

test('the first address of a new customer keeps the picked place too', function () {
    $this->post(route('customers.store'), [
        'type' => 'residential',
        'first_name' => 'Jane',
        'last_name' => 'Cooper',
        'phones' => [['number' => '604-555-0142', 'label' => 'mobile', 'is_primary' => true]],
        'add_property' => true,
        'property' => placesAddress(),
    ])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Property::sole()->google_place_id))->toBe('ChIJs0-pQ_FzhlQRi_OBm-qWkbs');
});

test('a new customer made in the job form keeps the picked place', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);

    $this->post(route('jobs.store'), [
        'brand_id' => $brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => [
            'first_name' => 'Sam',
            'phone' => '(778) 555-0199',
            'property' => placesAddress(['unit' => '4']),
        ],
    ])->assertSessionHasNoErrors();

    $property = inCompany($this->company, fn () => Property::query()->where('google_place_id', 'ChIJs0-pQ_FzhlQRi_OBm-qWkbs')->sole());
    expect((float) $property->latitude)->toBe(49.246453)->and($property->unit)->toBe('4');
});

test('place data of another company\'s property is not reachable', function () {
    $this->post(route('properties.store', $this->customer), placesAddress())->assertSessionHasNoErrors();

    expect(inCompany(Company::factory()->create(), fn () => Property::query()->whereNotNull('google_place_id')->count()))->toBe(0);
});
