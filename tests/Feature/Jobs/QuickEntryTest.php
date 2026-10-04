<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'America/Regina']);
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
});

function quickEntryPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'quick_booking' => true,
        'brand_id' => test()->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => [
            'first_name' => 'Jane Quick',
            'phone' => '+16045550101',
            'property' => ['country' => 'CA'],
        ],
        'add_visit' => true,
        'visit' => [
            'date' => '2030-01-15',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'assignee_ids' => [test()->owner->id],
        ],
    ], $overrides);
}

test('book opens a short form with the selected date and the full job form stays available', function () {
    $this->get(route('jobs.create', ['book' => 1, 'date' => '2030-01-15']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('jobs/quick-book')
            ->where('booking', true)->where('bookingDate', '2030-01-15')->where('openInvoice', false));
    $this->get(route('jobs.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('jobs/form'));
});

test('a name phone and time create the customer job and visit together without inventing an address', function () {
    $response = $this->post(route('jobs.store'), quickEntryPayload());
    $job = inCompany($this->company, fn () => ServiceJob::with('customer.primaryPhone', 'property', 'visits.assignees')->sole());
    $response->assertSessionHasNoErrors()->assertRedirect(route('jobs.show', $job));

    expect($job->customer->display_name)->toBe('Jane Quick')
        ->and($job->customer->primaryPhone->number)->toBe('+16045550101')
        ->and($job->property->line1)->toBe('')
        ->and($job->property->city)->toBe('')
        ->and($job->property->fullAddress())->toBe('')
        ->and($job->status)->toBe(JobStatus::Scheduled)
        ->and($job->visits->sole()->scheduled_start->utc()->toDateTimeString())->toBe('2030-01-15 15:00:00')
        ->and($job->visits->sole()->assignees->pluck('id')->all())->toBe([$this->owner->id]);

    $this->get(route('jobs.show', $job))->assertOk()->assertInertia(fn (Assert $page) => $page->where('job.property.full_address', ''));
});

test('a failed booking creates no partial customer job or visit and does not consume a number', function () {
    $number = $this->company->fresh()->job_next_number;
    $this->post(route('jobs.store'), quickEntryPayload(['visit' => ['end_time' => '08:00']]))
        ->assertSessionHasErrors('visit.end_time');
    expect(inCompany($this->company, fn () => Customer::count()))->toBe(0)
        ->and(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0)
        ->and($this->company->fresh()->job_next_number)->toBe($number);
});

test('quick entry still requires a customer name and valid phone and full entry still requires an address', function () {
    $this->post(route('jobs.store'), quickEntryPayload(['new_customer' => ['first_name' => '', 'phone' => 'x']]))
        ->assertSessionHasErrors('new_customer.first_name');
    $this->post(route('jobs.store'), quickEntryPayload(['new_customer' => ['phone' => 'x']]))
        ->assertSessionHasErrors('new_customer.phone');
    $this->post(route('jobs.store'), quickEntryPayload(['quick_booking' => false]))
        ->assertSessionHasErrors(['new_customer.property.line1', 'new_customer.property.city']);
    $this->post(route('jobs.store'), ['brand_id' => $this->brand->id, 'job_type' => 'repair'])
        ->assertSessionHasErrors(['customer_id', 'property_id']);
    expect(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0);
});

test('quick booking selects an existing customer and preserves their preferences without a duplicate', function () {
    $customer = Customer::factory()->for($this->company)->create(['notes' => 'Please text.']);
    $property = Property::factory()->for($customer)->create();
    $this->get(route('jobs.lookup', ['search' => $customer->first_name]))->assertOk()
        ->assertJsonPath('customers.0.id', $customer->id)->assertJsonPath('customers.0.notes', 'Please text.');
    $this->post(route('jobs.store'), quickEntryPayload([
        'new_customer_mode' => false, 'customer_id' => $customer->id, 'property_id' => $property->id,
    ]))->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Customer::count()))->toBe(1)
        ->and(inCompany($this->company, fn () => ServiceJob::sole()->customer_id))->toBe($customer->id)
        ->and($customer->fresh()->notes)->toBe('Please text.');
});

test('quick booking cannot use another company customer brand or technician', function () {
    $foreign = Company::factory()->create();
    $customer = Customer::factory()->for($foreign)->create();
    $property = Property::factory()->for($customer)->create();
    $brand = Brand::factory()->for($foreign)->create();
    $tech = memberOf($foreign, UserRole::Technician);
    $this->get(route('jobs.lookup', ['search' => $customer->first_name]))->assertOk()->assertJsonCount(0, 'customers');
    $this->post(route('jobs.store'), quickEntryPayload([
        'new_customer_mode' => false, 'customer_id' => $customer->id, 'property_id' => $property->id,
    ]))->assertSessionHasErrors('customer_id');
    $this->post(route('jobs.store'), quickEntryPayload(['brand_id' => $brand->id]))->assertSessionHasErrors('brand_id');
    $this->post(route('jobs.store'), quickEntryPayload(['visit' => ['assignee_ids' => [$tech->id]]]))
        ->assertSessionHasErrors('visit.assignee_ids.0');
    expect(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0);
});

test('an empty invoice list leads directly to customer entry then to prices without creating an empty invoice', function () {
    $this->get(route('invoices.start'))->assertRedirect(route('jobs.create', ['invoice' => 1]));
    $this->get(route('jobs.create', ['invoice' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('jobs/quick-book')->where('booking', false)->where('openInvoice', true));
    $response = $this->post(route('jobs.store'), quickEntryPayload(['add_visit' => false, 'open_invoice' => true]));
    $job = inCompany($this->company, fn () => ServiceJob::sole());
    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.create', $job));
    expect(inCompany($this->company, fn () => Invoice::count()))->toBe(0)
        ->and($job->visits()->count())->toBe(0);
    $this->get(route('invoices.create', $job))->assertOk()->assertInertia(fn (Assert $page) => $page->component('billing/form'));
    $this->post(route('invoices.store', $job), documentPayload())->assertSessionHasNoErrors()->assertRedirect();
    expect(inCompany($this->company, fn () => Invoice::sole()->total))->toBe(28050);
});

test('invoice entry lists only visible jobs and rejects non-office access', function () {
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $job = ServiceJob::factory()->for($property)->create(['brand_id' => $this->brand->id]);
    ServiceJob::factory()->create();
    $this->get(route('invoices.start'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('invoices/start')
        ->has('jobs', 1)->where('jobs.0.id', $job->id));
    $this->actingAs(memberOf($this->company, UserRole::Technician))->get(route('invoices.start'))->assertForbidden();
});

test('an address picked on the booking form is saved with unit, place and problem', function () {
    $this->post(route('jobs.store'), quickEntryPayload([
        'description' => 'Washer not draining',
        'new_customer' => ['property' => [
            'line1' => '295 Guildford Way', 'unit' => '1204', 'city' => 'Port Moody', 'region' => 'BC',
            'postal_code' => 'V3H 0A1', 'google_place_id' => 'place-123', 'latitude' => '49.28', 'longitude' => '-122.83',
        ]],
    ]))->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::with('property')->sole());
    expect($job->description)->toBe('Washer not draining')
        ->and($job->property->only(['line1', 'unit', 'city', 'google_place_id']))
        ->toBe(['line1' => '295 Guildford Way', 'unit' => '1204', 'city' => 'Port Moody', 'google_place_id' => 'place-123']);
});
