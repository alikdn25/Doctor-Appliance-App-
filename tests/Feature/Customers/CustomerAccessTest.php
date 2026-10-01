<?php

use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->customer = Customer::factory()->for($this->company)->create();
    $this->property = Property::factory()->for($this->customer)->create();
    $this->appliance = Appliance::factory()->for($this->property)->create();
});

test('owners and admins can open every customer page', function (UserRole $role) {
    $this->actingAs(memberOf($this->company, $role));

    $this->get(route('customers.index'))->assertOk();
    $this->get(route('customers.create'))->assertOk();
    $this->get(route('customers.show', $this->customer))->assertOk();
    $this->get(route('customers.edit', $this->customer))->assertOk();
    $this->get(route('appliances.show', $this->appliance))->assertOk();
    $this->getJson(route('customers.duplicates'))->assertOk();
})->with([UserRole::Owner, UserRole::Admin]);

test('technicians have no access to customers until jobs exist', function () {
    $this->actingAs(memberOf($this->company, UserRole::Technician));

    $this->get(route('customers.index'))->assertForbidden();
    $this->get(route('customers.create'))->assertForbidden();
    $this->post(route('customers.store'), ['type' => 'residential', 'first_name' => 'X'])->assertForbidden();
    $this->get(route('customers.show', $this->customer))->assertForbidden();
    $this->get(route('customers.edit', $this->customer))->assertForbidden();
    $this->put(route('customers.update', $this->customer), ['type' => 'residential', 'first_name' => 'X'])->assertForbidden();
    $this->delete(route('customers.destroy', $this->customer))->assertForbidden();
    $this->getJson(route('customers.duplicates'))->assertForbidden();

    $this->post(route('properties.store', $this->customer), ['line1' => 'X', 'city' => 'Y', 'country' => 'CA'])->assertForbidden();
    $this->put(route('properties.update', $this->property), ['line1' => 'X', 'city' => 'Y', 'country' => 'CA'])->assertForbidden();
    $this->delete(route('properties.destroy', $this->property))->assertForbidden();

    $this->post(route('appliances.store', $this->property), ['type' => 'washer'])->assertForbidden();
    $this->get(route('appliances.show', $this->appliance))->assertForbidden();
    $this->put(route('appliances.update', $this->appliance), ['type' => 'washer'])->assertForbidden();
    $this->delete(route('appliances.destroy', $this->appliance))->assertForbidden();

    expect($this->customer->fresh()->trashed())->toBeFalse();
});

test('guests are sent to the login page', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});
