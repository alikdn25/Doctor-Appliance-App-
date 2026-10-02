<?php

use App\Enums\CustomerType;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Owner));
});

function customerPayload(array $overrides = []): array
{
    return array_replace([
        'type' => 'residential',
        'first_name' => 'Jane',
        'last_name' => 'Cooper',
        'lead_source' => 'homestars',
        'tags' => ['VIP', ' vip ', 'Warranty'],
        'notes' => 'Prefers mornings.',
        'phones' => [
            ['label' => 'mobile', 'number' => '(604) 555-0142', 'is_primary' => false],
            ['label' => 'work', 'number' => '778-555-0100', 'is_primary' => true],
        ],
        'emails' => [
            ['label' => 'personal', 'email' => 'Jane@Example.com', 'is_primary' => true],
        ],
        'add_property' => true,
        'property' => [
            'line1' => '8450 128 St',
            'unit' => '12',
            'city' => 'Surrey',
            'region' => 'BC',
            'postal_code' => 'V3W 4G1',
            'country' => 'ca',
            'gate_code' => '#1234',
            'site_contact_name' => 'Sam Tenant',
            'site_contact_phone' => '778-555-0110',
        ],
    ], $overrides);
}

test('an owner can create a customer with contacts and a first property', function () {
    $response = $this->post(route('customers.store'), customerPayload());

    $customer = inCompany($this->company, fn () => Customer::with(['phones', 'emails', 'properties'])->sole());
    $response->assertRedirect(route('customers.show', $customer));

    expect($customer->display_name)->toBe('Jane Cooper')
        ->and($customer->type)->toBe(CustomerType::Residential)
        ->and($customer->tags)->toBe(['VIP', 'Warranty'])
        ->and($customer->phones)->toHaveCount(2)
        ->and($customer->phones->first()->number)->toBe('778-555-0100')
        ->and($customer->phones->first()->is_primary)->toBeTrue()
        ->and($customer->phones->last()->number_normalized)->toBe('+16045550142')
        ->and($customer->emails->sole()->email)->toBe('jane@example.com')
        ->and($customer->properties->sole())
        ->city->toBe('Surrey')
        ->country->toBe('CA')
        ->is_primary->toBeTrue()
        ->site_contact_name->toBe('Sam Tenant')
        ->site_contact_phone->toBe('778-555-0110');
});

test('a customer can be created without a property', function () {
    $this->post(route('customers.store'), customerPayload(['add_property' => false, 'property' => []]))
        ->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Property::count()))->toBe(0);
});

test('the property address is required when adding a property', function () {
    $this->post(route('customers.store'), customerPayload(['property' => ['country' => 'CA']]))
        ->assertSessionHasErrors(['property.line1', 'property.city']);
});

test('a name or company name is required', function () {
    $this->post(route('customers.store'), customerPayload(['first_name' => '', 'last_name' => '', 'company_name' => '']))
        ->assertSessionHasErrors('first_name');
});

test('invalid phones, emails and enum values are rejected', function () {
    $this->post(route('customers.store'), customerPayload([
        'type' => 'alien',
        'lead_source' => 'tv',
        'phones' => [['label' => 'mobile', 'number' => '12']],
        'emails' => [['label' => 'personal', 'email' => 'not-an-email']],
    ]))->assertSessionHasErrors(['type', 'lead_source', 'phones.0.number', 'emails.0.email']);
});

test('commercial customers are displayed by company name', function () {
    $this->post(route('customers.store'), customerPayload([
        'type' => 'property_manager',
        'company_name' => 'Westside PM',
    ]))->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Customer::sole()->display_name))->toBe('Westside PM');
});

test('updating a customer syncs phones and emails', function () {
    $this->post(route('customers.store'), customerPayload());
    $customer = inCompany($this->company, fn () => Customer::with('phones')->sole());
    $keep = $customer->phones->firstWhere('number', '778-555-0100');

    $this->put(route('customers.update', $customer), customerPayload([
        'last_name' => 'Smith',
        'phones' => [
            ['id' => $keep->id, 'label' => 'work', 'number' => '778-555-0101', 'is_primary' => false],
            ['label' => 'home', 'number' => '604-555-0199', 'is_primary' => true],
        ],
        'emails' => [],
    ]))->assertRedirect(route('customers.show', $customer));

    $customer = inCompany($this->company, fn () => $customer->fresh(['phones', 'emails', 'properties']));

    expect($customer->display_name)->toBe('Jane Smith')
        ->and($customer->phones)->toHaveCount(2)
        ->and($customer->phones->firstWhere('id', $keep->id)->number)->toBe('778-555-0101')
        ->and($customer->phones->where('is_primary', true)->sole()->number)->toBe('604-555-0199')
        ->and($customer->emails)->toBeEmpty()
        ->and($customer->properties)->toHaveCount(1);
});

test('exactly one phone is primary when several are marked', function () {
    $this->post(route('customers.store'), customerPayload([
        'phones' => [
            ['label' => 'mobile', 'number' => '604-555-0001', 'is_primary' => true],
            ['label' => 'mobile', 'number' => '604-555-0002', 'is_primary' => true],
        ],
    ]));

    expect(inCompany($this->company, fn () => Customer::sole()->phones()->where('is_primary', true)->count()))->toBe(1);
});

test('the customer card shows properties and appliances', function () {
    $customer = Customer::factory()->for($this->company)->withPhone()->create();
    $property = Property::factory()->for($customer)->create(['site_contact_name' => 'Sam Tenant', 'site_contact_phone' => '778-555-0110']);
    Appliance::factory()->for($property)->create(['type' => 'washer', 'model_number' => 'wm3900hwa ']);

    $this->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/show')
            ->where('customer.display_name', $customer->display_name)
            ->has('customer.phones', 1)
            ->has('customer.properties', 1)
            ->where('customer.properties.0.site_contact_name', 'Sam Tenant')
            ->where('customer.properties.0.site_contact_phone', '778-555-0110')
            ->where('customer.properties.0.appliances.0.model_number', 'WM3900HWA')
            ->where('canUpdate', true));
});

test('deleting a customer soft-deletes their properties and appliances', function () {
    $customer = Customer::factory()->for($this->company)->create();
    $property = Property::factory()->for($customer)->create();
    $appliance = Appliance::factory()->for($property)->create();

    $this->delete(route('customers.destroy', $customer))->assertRedirect(route('customers.index'));

    expect(Customer::withoutCompanyScope()->withTrashed()->find($customer->id)->trashed())->toBeTrue()
        ->and(Property::withoutCompanyScope()->withTrashed()->find($property->id)->trashed())->toBeTrue()
        ->and(Appliance::withoutCompanyScope()->withTrashed()->find($appliance->id)->trashed())->toBeTrue()
        ->and(AuditLog::where('action', 'customer.deleted')->exists())->toBeTrue();

    $this->get(route('customers.show', $customer))->assertNotFound();
    $this->get(route('appliances.show', $appliance))->assertNotFound();
});

test('the customer list is paginated and filterable by type and tag', function () {
    Customer::factory()->for($this->company)->count(3)->create();
    Customer::factory()->for($this->company)->commercial('Acme Strata')->create(['type' => 'strata', 'tags' => ['Duct cleaning']]);

    $this->get(route('customers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/index')
            ->where('customers.total', 4)
            ->where('tags', ['Duct cleaning']));

    $this->get(route('customers.index', ['type' => 'strata']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers.total', 1)
            ->where('customers.data.0.display_name', 'Acme Strata'));

    $this->get(route('customers.index', ['tag' => 'Duct cleaning']))
        ->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
});

test('the create and edit forms render', function () {
    $customer = Customer::factory()->for($this->company)->withPhone()->create();

    $this->get(route('customers.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/form')->where('customer', null)->has('leadSources', 10));
    $this->get(route('customers.edit', $customer))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customer.id', $customer->id)->has('customer.phones', 1));
});
