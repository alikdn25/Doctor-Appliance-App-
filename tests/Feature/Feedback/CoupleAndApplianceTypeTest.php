<?php

use App\Enums\ApplianceType;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Owner));
});

function couplePayload(array $phones, string $style = 'auto'): array
{
    return ['type' => 'residential', 'first_name' => 'Oleg', 'last_name' => 'Kim', 'avatar_style' => $style, 'phones' => $phones, 'emails' => []];
}

test('adding a second person makes the automatic icon a couple', function () {
    $this->post(route('customers.store'), couplePayload([
        ['label' => 'mobile', 'number' => '604-555-0101', 'is_primary' => true],
        ['label' => 'mobile', 'contact_name' => 'Anna (wife)', 'number' => '604-555-0102', 'is_primary' => false],
    ]))->assertSessionHasNoErrors();

    $customer = inCompany($this->company, fn () => Customer::query()->sole());
    expect($customer->has_second_contact)->toBeTrue()
        ->and($customer->avatarIcon())->toBe('couple');

    $this->get(route('customers.show', $customer))->assertInertia(fn (Assert $page) => $page
        ->where('customer.avatar_icon', 'couple')
        ->where('customer.phones.1.contact_name', 'Anna (wife)'));

    // Removing the second person brings the face from the first name back.
    $phones = inCompany($this->company, fn () => $customer->phones()->orderBy('id')->get());
    $this->put(route('customers.update', $customer), couplePayload([
        ['id' => $phones[0]->id, 'label' => 'mobile', 'number' => '604-555-0101', 'is_primary' => true],
    ]))->assertSessionHasNoErrors();

    expect($customer->fresh()->has_second_contact)->toBeFalse()
        ->and($customer->fresh()->avatarIcon())->not->toBe('couple');
});

test('the customer own name on a phone is not a second person', function () {
    $this->post(route('customers.store'), couplePayload([
        ['label' => 'mobile', 'contact_name' => 'Oleg', 'number' => '604-555-0101', 'is_primary' => true],
    ]))->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Customer::query()->sole()->has_second_contact))->toBeFalse();
});

test('a couple icon can be picked by hand and a manual face wins over automatic', function () {
    $customer = Customer::factory()->for($this->company)->create(['first_name' => 'Sasha']);

    $this->patch(route('customers.icon', $customer), ['avatar_style' => 'couple'])->assertRedirect();
    expect($customer->fresh()->avatarIcon())->toBe('couple');

    $customer->forceFill(['has_second_contact' => true, 'avatar_style' => 'man'])->save();
    expect($customer->fresh()->avatarIcon())->toBe('man');
});

test('the appliance type of a booked job can be corrected', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $job = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id]);
    $appliance = inCompany($this->company, fn () => Appliance::factory()->for($property)->create(['type' => 'washer', 'manufacturer' => 'Blomberg']));
    inCompany($this->company, fn () => $job->appliances()->attach($appliance));

    $this->put(route('jobs.appliances.update', [$job, $appliance]), [
        'type' => 'refrigerator', 'manufacturer' => 'Blomberg', 'model_number' => 'KND', 'serial_number' => '',
    ])->assertSessionHasNoErrors();

    $appliance = inCompany($this->company, fn () => $appliance->fresh());
    expect($appliance->type)->toBe(ApplianceType::Refrigerator)->and($appliance->model_number)->toBe('KND');

    $this->put(route('jobs.appliances.update', [$job, $appliance]), ['type' => 'spaceship'])->assertSessionHasErrors('type');
});

test('a technician of the job can correct the appliance type', function () {
    $tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $job = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id]);
    JobVisit::factory()->for($job, 'job')->assignedTo($tech)->create(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(2)]);
    $appliance = inCompany($this->company, fn () => Appliance::factory()->for($property)->create(['type' => 'washer']));
    inCompany($this->company, fn () => $job->appliances()->attach($appliance));

    $this->actingAs($tech)->put(route('jobs.appliances.update', [$job, $appliance]), ['type' => 'dryer'])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $appliance->fresh()->type))->toBe(ApplianceType::Dryer);
});
