<?php

use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Admin));
    $this->customer = Customer::factory()->for($this->company)->create();
});

function propertyPayload(array $overrides = []): array
{
    return array_replace([
        'label' => 'Rental',
        'line1' => '4120 Main St',
        'unit' => '204',
        'city' => 'Vancouver',
        'region' => 'BC',
        'postal_code' => 'V5V 3P6',
        'country' => 'CA',
        'access_notes' => 'Side door.',
        'gate_code' => '#204',
        'site_contact_name' => 'Sam Tenant',
        'site_contact_phone' => '778-555-0110',
    ], $overrides);
}

test('a property can be added to a customer; the first one becomes primary', function () {
    $this->post(route('properties.store', $this->customer), propertyPayload())
        ->assertRedirect(route('customers.show', $this->customer));

    $property = inCompany($this->company, fn () => Property::sole());

    expect($property->customer_id)->toBe($this->customer->id)
        ->and($property->company_id)->toBe($this->company->id)
        ->and($property->is_primary)->toBeTrue()
        ->and($property->site_contact_name)->toBe('Sam Tenant')
        ->and($property->fullAddress())->toBe('Unit 204, 4120 Main St, Vancouver, BC V5V 3P6');
});

test('marking another property as primary moves the flag', function () {
    $first = Property::factory()->for($this->customer)->create(['is_primary' => true]);

    $this->post(route('properties.store', $this->customer), propertyPayload(['is_primary' => true]));

    $properties = inCompany($this->company, fn () => $this->customer->properties()->get());
    expect($properties->where('is_primary', true))->toHaveCount(1)
        ->and($properties->firstWhere('id', $first->id)->is_primary)->toBeFalse();
});

test('a property can be updated', function () {
    $property = Property::factory()->for($this->customer)->create();

    $this->put(route('properties.update', $property), propertyPayload(['gate_code' => '9999', 'site_contact_phone' => '604-555-0000']))
        ->assertRedirect(route('customers.show', $this->customer));

    expect($property->fresh())->gate_code->toBe('9999')->site_contact_phone->toBe('604-555-0000');
});

test('the address is validated', function () {
    $this->post(route('properties.store', $this->customer), propertyPayload(['line1' => '', 'city' => '', 'country' => 'CAN']))
        ->assertSessionHasErrors(['line1', 'city', 'country']);
});

test('deleting the primary property promotes another one and removes its appliances', function () {
    $primary = Property::factory()->for($this->customer)->create(['is_primary' => true]);
    $other = Property::factory()->for($this->customer)->create(['is_primary' => false]);
    $appliance = Appliance::factory()->for($primary)->create();

    $this->delete(route('properties.destroy', $primary))->assertRedirect(route('customers.show', $this->customer));

    expect($other->fresh()->is_primary)->toBeTrue()
        ->and(Appliance::withoutCompanyScope()->withTrashed()->find($appliance->id)->trashed())->toBeTrue();
});
