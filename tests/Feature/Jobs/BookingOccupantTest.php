<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;

/**
 * Booking with an occupant and the payer's billing address (owner's update, October 24): the customer is the one
 * who pays; the job's address may have someone else living there, saved with the address for next time.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
});

function occupantBooking(array $overrides = []): array
{
    return array_replace_recursive([
        'quick_booking' => true,
        'brand_id' => test()->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => [
            'first_name' => 'Pat',
            'last_name' => 'Manager',
            'phone' => '+16045550111',
            'email' => 'Pat@Example.com',
            'property' => ['line1' => '500 Rental Ave', 'unit' => '12', 'city' => 'Surrey', 'country' => 'CA'],
        ],
        'occupant' => ['enabled' => true, 'name' => 'Tina Tenant', 'phone' => '604 555 0122', 'access_notes' => 'Buzz 12, dog in the yard'],
        'billing' => ['line1' => '1 Office Tower', 'city' => 'Vancouver', 'postal_code' => 'v6b 1a1', 'country' => 'CA'],
        'add_visit' => true,
        'visit' => ['date' => '2030-01-15', 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => [test()->owner->id]],
    ], $overrides);
}

test('a booking with an occupant saves the payer, the billing address and the occupant on the job address', function () {
    $this->post(route('jobs.store'), occupantBooking())->assertSessionHasNoErrors();

    inCompany($this->company, function () {
        $job = ServiceJob::with(['customer.primaryEmail', 'property'])->sole();

        expect($job->customer->display_name)->toBe('Pat Manager')
            ->and($job->customer->primaryEmail->email)->toBe('pat@example.com')
            ->and($job->customer->billing_line1)->toBe('1 Office Tower')
            ->and($job->customer->billing_city)->toBe('Vancouver')
            ->and($job->customer->billingAddress())->toContain('1 Office Tower')
            ->and($job->property->line1)->toBe('500 Rental Ave')
            ->and($job->property->site_contact_name)->toBe('Tina Tenant')
            ->and($job->property->site_contact_phone)->toBe('+16045550122')
            ->and($job->property->access_notes)->toBe('Buzz 12, dog in the yard');
    });
});

test('the occupant is offered again with the address on the next booking', function () {
    $this->post(route('jobs.store'), occupantBooking())->assertSessionHasNoErrors();
    $customer = inCompany($this->company, fn () => Customer::sole());

    $this->getJson(route('jobs.lookup', ['search' => '6045550111', 'by' => 'phone']))
        ->assertOk()
        ->assertJsonPath('customers.0.email', 'pat@example.com')
        ->assertJsonPath('customers.0.billing.line1', '1 Office Tower')
        ->assertJsonPath('customers.0.properties.0.occupant.name', 'Tina Tenant')
        ->assertJsonPath('customers.0.properties.0.occupant.phone', '+16045550122');

    // Booking the same address again with the occupant switched off clears it (the tenant moved out).
    $property = inCompany($this->company, fn () => Property::sole());
    $this->post(route('jobs.store'), occupantBooking([
        'new_customer_mode' => false, 'customer_id' => $customer->id, 'property_id' => $property->id,
        'occupant' => ['enabled' => false],
    ]))->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => $property->fresh()->site_contact_name))->toBeNull()
        ->and(inCompany($this->company, fn () => ServiceJob::count()))->toBe(2);
});

test('an existing customer books a new address with an occupant and it is saved to the customer', function () {
    $customer = Customer::factory()->for($this->company)->create();
    $home = Property::factory()->for($customer)->create(['line1' => '1 Home St', 'is_primary' => true]);

    $this->post(route('jobs.store'), occupantBooking([
        'new_customer_mode' => false, 'customer_id' => $customer->id,
        'new_property' => ['line1' => '2 Rental Rd', 'city' => 'Burnaby', 'country' => 'CA'],
        'billing' => ['line1' => ''],
    ]))->assertSessionHasNoErrors();

    inCompany($this->company, function () use ($customer, $home) {
        $job = ServiceJob::with('property')->sole();
        expect($job->property->line1)->toBe('2 Rental Rd')
            ->and($job->property->customer_id)->toBe($customer->id)
            ->and($job->property->is_primary)->toBeFalse()
            ->and($job->property->site_contact_name)->toBe('Tina Tenant')
            ->and($home->fresh()->is_primary)->toBeTrue()
            // An empty billing address means the same as the primary one.
            ->and($customer->fresh()->billing_line1)->toBeNull()
            ->and($customer->fresh()->billingAddress())->toContain('1 Home St');
    });
});

test('a wrong occupant phone is refused and nothing is booked', function () {
    $this->post(route('jobs.store'), occupantBooking(['occupant' => ['phone' => '12']]))
        ->assertSessionHasErrors('occupant.phone');

    expect(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0);
});

test('the occupant cannot be written onto another company\'s address', function () {
    $other = Company::factory()->create();
    $foreign = Property::factory()->for(Customer::factory()->for($other))->create(['site_contact_name' => 'Keep Me']);
    $customer = Customer::factory()->for($this->company)->create();
    Property::factory()->for($customer)->create(['is_primary' => true]);

    $this->post(route('jobs.store'), occupantBooking([
        'new_customer_mode' => false, 'customer_id' => $customer->id, 'property_id' => $foreign->id,
    ]))->assertSessionHasErrors('property_id');

    expect(inCompany($other, fn () => $foreign->fresh()->site_contact_name))->toBe('Keep Me');
});

test('the customer form saves and clears the billing address', function () {
    $customer = Customer::factory()->for($this->company)->create(['first_name' => 'Bill']);
    $payload = ['type' => 'residential', 'first_name' => 'Bill', 'phones' => [], 'emails' => [['label' => 'personal', 'email' => 'bill@example.com']]];

    $this->put(route('customers.update', $customer), [...$payload, 'billing' => ['line1' => '9 Pay St', 'city' => 'Delta', 'country' => 'CA']])
        ->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $customer->fresh()->billing_line1))->toBe('9 Pay St');

    $this->put(route('customers.update', $customer), [...$payload, 'billing' => ['line1' => '9 Pay St', 'city' => '']])
        ->assertSessionHasErrors('billing.city');

    $this->put(route('customers.update', $customer), [...$payload, 'billing' => ['line1' => '']])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $customer->fresh()->billing_line1))->toBeNull();
});
