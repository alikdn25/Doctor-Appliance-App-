<?php

use App\Enums\JobStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Customers\EmptyAddresses;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * No address without an address (owner's fixes, October 19): an empty address is never created, the first one is
 * primary, a typed address fills an empty one, and old empty addresses are cleaned up.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA']);
    $this->owner = memberOf($this->company);
    $this->customer = Customer::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
});

function emptyAddressOf(Company $company, Customer $customer, bool $primary = true): Property
{
    return inCompany($company, fn () => Property::query()->forceCreate([
        'company_id' => $company->id, 'customer_id' => $customer->id,
        'line1' => '', 'city' => '', 'country' => 'CA', 'is_primary' => $primary,
    ]));
}

function customerEditPayload(Customer $customer, array $property): array
{
    return [
        'type' => 'residential', 'first_name' => $customer->first_name, 'last_name' => $customer->last_name,
        'phones' => [], 'emails' => [], 'add_property' => true, 'property' => $property,
    ];
}

test('the customer edit form shows the main address and saves it in place', function () {
    $property = Property::factory()->for($this->customer)->create(['line1' => '1 Old Road', 'is_primary' => true]);

    $this->get(route('customers.edit', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('customer.property.id', $property->id)
        ->where('customer.property.line1', '1 Old Road'));

    $this->put(route('customers.update', $this->customer), customerEditPayload($this->customer, [
        'id' => $property->id, 'line1' => '2 New Road', 'city' => 'Surrey', 'country' => 'CA',
    ]))->assertSessionHasNoErrors();

    $addresses = inCompany($this->company, fn () => Property::query()->where('customer_id', $this->customer->id)->get());
    expect($addresses)->toHaveCount(1)
        ->and($addresses->sole()->line1)->toBe('2 New Road')
        ->and($addresses->sole()->is_primary)->toBeTrue();
});

test('an address typed for a customer with an empty one fills it instead of adding a second', function () {
    $empty = emptyAddressOf($this->company, $this->customer);

    $this->put(route('customers.update', $this->customer), customerEditPayload($this->customer, [
        'line1' => '5 Filled Ave', 'city' => 'Burnaby', 'country' => 'CA',
    ]))->assertSessionHasNoErrors();

    $addresses = inCompany($this->company, fn () => Property::query()->where('customer_id', $this->customer->id)->get());
    expect($addresses)->toHaveCount(1)
        ->and($addresses->sole()->id)->toBe($empty->id)
        ->and($addresses->sole()->line1)->toBe('5 Filled Ave');
});

test('the first address of a customer is primary and an address is never saved empty', function () {
    $this->post(route('properties.store', $this->customer), ['line1' => '7 First St', 'city' => 'Delta', 'country' => 'CA', 'is_primary' => false])
        ->assertSessionHasNoErrors();
    $this->post(route('properties.store', $this->customer), ['line1' => '', 'city' => '', 'country' => 'CA'])
        ->assertSessionHasErrors(['line1', 'city']);

    $addresses = inCompany($this->company, fn () => Property::query()->where('customer_id', $this->customer->id)->get());
    expect($addresses)->toHaveCount(1)->and($addresses->sole()->is_primary)->toBeTrue();
});

test('"Add address" on the job page saves it on the job and the customer', function () {
    $empty = emptyAddressOf($this->company, $this->customer);
    $job = ServiceJob::factory()->for($empty)->create();

    $this->put(route('jobs.address', $job), ['line1' => '9 Job Lane', 'city' => 'Richmond', 'country' => 'CA'])
        ->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => $job->fresh('property'));
    expect($job->property_id)->toBe($empty->id)
        ->and($job->property->line1)->toBe('9 Job Lane')
        ->and(inCompany($this->company, fn () => Property::query()->where('customer_id', $this->customer->id)->count()))->toBe(1);

    $this->put(route('jobs.address', $job), ['line1' => '', 'city' => '', 'country' => 'CA'])->assertSessionHasErrors('line1');
});

test('the job address can move to another address of the same customer only', function () {
    $home = Property::factory()->for($this->customer)->create(['line1' => '1 Home St', 'is_primary' => true]);
    $rental = Property::factory()->for($this->customer)->create(['line1' => '2 Rental St']);
    $job = ServiceJob::factory()->for($home)->create();
    $stranger = Property::factory()->for(Customer::factory()->for($this->company))->create();

    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('otherAddresses.0.id', $rental->id));

    $this->put(route('jobs.address', $job), ['property_id' => $rental->id])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $job->fresh()->property_id))->toBe($rental->id);

    $this->put(route('jobs.address', $job), ['property_id' => $stranger->id])->assertSessionHasErrors('property_id');
    expect(inCompany($this->company, fn () => $job->fresh()->property_id))->toBe($rental->id);
});

test('old empty addresses: jobs move to the only real address and empty ones without jobs go to the trash', function () {
    $real = Property::factory()->for($this->customer)->create(['line1' => '3 Real Rd', 'is_primary' => false]);
    $empty = emptyAddressOf($this->company, $this->customer);
    $job = ServiceJob::factory()->for($empty)->create(['status' => JobStatus::Scheduled]);

    // Another customer: two real addresses, so the job on the empty one stays where it is.
    $other = Customer::factory()->for($this->company)->create();
    Property::factory()->for($other)->count(2)->create();
    $otherEmpty = emptyAddressOf($this->company, $other, false);
    $otherJob = ServiceJob::factory()->for($otherEmpty)->create();

    // A third: only an empty address with no jobs.
    $lonely = Customer::factory()->for($this->company)->create();
    $lonelyEmpty = emptyAddressOf($this->company, $lonely);

    EmptyAddresses::clean();

    inCompany($this->company, function () use ($job, $real, $empty, $otherJob, $otherEmpty, $lonelyEmpty) {
        expect($job->fresh()->property_id)->toBe($real->id)
            ->and(Property::query()->find($empty->id))->toBeNull()
            ->and(Property::onlyTrashed()->find($empty->id))->not->toBeNull()
            ->and($real->fresh()->is_primary)->toBeTrue()
            ->and($otherJob->fresh()->property_id)->toBe($otherEmpty->id)
            ->and(Property::query()->find($otherEmpty->id))->not->toBeNull()
            ->and(Property::onlyTrashed()->find($lonelyEmpty->id))->not->toBeNull();
    });
});
