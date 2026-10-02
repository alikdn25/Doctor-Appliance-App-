<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\Vertical;
use App\Models\Appliance;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobStatusChange;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tom Tech']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')
        ->create(['first_name' => 'Jane', 'last_name' => 'Cooper', 'lead_source' => 'directory']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '8450 128 St', 'city' => 'Surrey']);
    $this->washer = Appliance::factory()->for($this->property)->create(['type' => 'washer', 'model_number' => 'WM3900']);
    $this->dryer = Appliance::factory()->for($this->property)->create(['type' => 'dryer']);

    $this->actingAs($this->owner);
});

function jobPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'brand_id' => test()->brand->id,
        'job_type' => 'repair',
        'lead_source' => 'google_ads',
        'customer_id' => test()->customer->id,
        'property_id' => test()->property->id,
        'appliance_ids' => [test()->washer->id],
        'description' => 'Washer does not drain.',
        'notes' => 'Call 30 min before.',
    ], $overrides);
}

test('the office creates a job for an existing customer', function () {
    $response = $this->post(route('jobs.store'), jobPayload());

    $job = inCompany($this->company, fn () => ServiceJob::with('appliances', 'statusChanges')->sole());
    $response->assertRedirect(route('jobs.show', $job));

    expect($job)
        ->number->toBe(1001)
        ->status->toBe(JobStatus::New)
        ->brand_id->toBe($this->brand->id)
        ->customer_id->toBe($this->customer->id)
        ->property_id->toBe($this->property->id)
        ->description->toBe('Washer does not drain.')
        ->created_by->toBe($this->owner->id)
        ->and($job->appliances->pluck('id')->all())->toBe([$this->washer->id])
        ->and($job->statusChanges)->toHaveCount(1)
        ->and($job->statusChanges->first())
        ->from_status->toBeNull()
        ->to_status->toBe(JobStatus::New)
        ->user_id->toBe($this->owner->id);

    expect($this->company->fresh()->job_next_number)->toBe(1002);
});

test('job numbers go up by one per company', function () {
    $this->post(route('jobs.store'), jobPayload());
    $this->post(route('jobs.store'), jobPayload());

    expect(inCompany($this->company, fn () => ServiceJob::orderBy('number')->pluck('number')->all()))->toBe([1001, 1002]);
});

test('a job with a first visit is scheduled in the company timezone', function () {
    // A timezone without daylight saving keeps the expected UTC times stable.
    $this->company->update(['timezone' => 'America/Regina']);

    $this->post(route('jobs.store'), jobPayload([
        'add_visit' => true,
        'visit' => [
            'date' => '2030-01-15',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'estimated_duration_minutes' => 90,
            'assignee_ids' => [$this->tech->id, $this->owner->id],
        ],
    ]))->assertRedirect();

    $job = inCompany($this->company, fn () => ServiceJob::with('visits.assignees', 'statusChanges')->sole());
    $visit = $job->visits->sole();

    expect($job->status)->toBe(JobStatus::Scheduled)
        ->and($visit->scheduled_start->utc()->toDateTimeString())->toBe('2030-01-15 15:00:00')
        ->and($visit->scheduled_end->utc()->toDateTimeString())->toBe('2030-01-15 17:00:00')
        ->and($visit->estimated_duration_minutes)->toBe(90)
        ->and($visit->assignees->pluck('id')->sort()->values()->all())->toBe(collect([$this->tech->id, $this->owner->id])->sort()->values()->all())
        ->and($job->statusChanges->pluck('to_status')->map->value->all())->toBe(['scheduled', 'new']);

    expect(DB::table('job_visit_user')->pluck('company_id')->unique()->all())->toBe([$this->company->id]);
});

test('a new customer can be created right in the job form', function () {
    $this->post(route('jobs.store'), [
        'brand_id' => $this->brand->id,
        'job_type' => 'repair',
        'lead_source' => 'website',
        'new_customer_mode' => true,
        'new_customer' => [
            'first_name' => 'Sam',
            'last_name' => 'Lee',
            'phone' => '(778) 555-0199',
            'email' => 'Sam@Example.com',
            'property' => ['line1' => '100 Main St', 'unit' => '4', 'city' => 'Burnaby', 'postal_code' => 'V5H 1A1', 'gate_code' => '#1234'],
        ],
        'new_appliances' => [['type' => 'refrigerator', 'manufacturer' => 'Samsung', 'model_number' => 'rf28 ', 'serial_number' => '']],
    ])->assertRedirect();

    inCompany($this->company, function () {
        $customer = Customer::where('first_name', 'Sam')->with('phones', 'emails', 'properties.appliances')->sole();
        $job = ServiceJob::sole();

        expect($customer)
            ->display_name->toBe('Sam Lee')
            ->lead_source->value->toBe('website')
            ->and($customer->phones->sole())->number->toBe('+17785550199')->is_primary->toBeTrue()
            ->and($customer->emails->sole()->email)->toBe('sam@example.com')
            ->and($customer->properties->sole())
            ->line1->toBe('100 Main St')
            ->unit->toBe('4')
            ->gate_code->toBe('#1234')
            ->country->toBe('CA')
            ->is_primary->toBeTrue()
            ->and($job->customer_id)->toBe($customer->id)
            ->and($job->property_id)->toBe($customer->properties->sole()->id)
            ->and($job->appliances()->sole())
            ->manufacturer->toBe('Samsung')
            ->model_number->toBe('RF28')
            ->property_id->toBe($customer->properties->sole()->id);
    });
});

test('a new customer needs a name, a valid phone and an address', function () {
    $this->post(route('jobs.store'), [
        'brand_id' => $this->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => ['phone' => '123', 'property' => ['line1' => '', 'city' => '']],
    ])->assertSessionHasErrors(['new_customer.first_name', 'new_customer.property.line1', 'new_customer.property.city']);

    $this->post(route('jobs.store'), [
        'brand_id' => $this->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Sam', 'phone' => '123', 'property' => ['line1' => '1 Main', 'city' => 'Surrey']],
    ])->assertSessionHasErrors('new_customer.phone');

    expect(inCompany($this->company, fn () => [Customer::count(), ServiceJob::count()]))->toBe([1, 0]);
});

test('the address and appliances must belong to the chosen customer', function () {
    $other = Customer::factory()->for($this->company)->create();
    $otherProperty = Property::factory()->for($other)->create();
    $otherAppliance = Appliance::factory()->for($otherProperty)->create();

    $this->post(route('jobs.store'), jobPayload(['property_id' => $otherProperty->id]))
        ->assertSessionHasErrors('property_id');
    $this->post(route('jobs.store'), jobPayload(['appliance_ids' => [$otherAppliance->id]]))
        ->assertSessionHasErrors('appliance_ids');
    $this->post(route('jobs.store'), jobPayload(['customer_id' => null]))
        ->assertSessionHasErrors('customer_id');

    expect(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0);
});

test('the visit window must end after it starts and only team members can be assigned', function () {
    $outsider = memberOf(Company::factory()->create(), UserRole::Technician);
    $inactive = memberOf($this->company, UserRole::Technician);
    DB::table('company_user')->where('user_id', $inactive->id)->update(['is_active' => false]);

    $this->post(route('jobs.store'), jobPayload([
        'add_visit' => true,
        'visit' => ['date' => '2030-01-15', 'start_time' => '11:00', 'end_time' => '09:00'],
    ]))->assertSessionHasErrors('visit.end_time');

    $this->post(route('jobs.store'), jobPayload([
        'add_visit' => true,
        'visit' => ['date' => '2030-01-15', 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => [$outsider->id, $inactive->id]],
    ]))->assertSessionHasErrors(['visit.assignee_ids.0', 'visit.assignee_ids.1']);

    expect(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0);
});

test('inactive brands cannot be picked for a new job', function () {
    $inactive = Brand::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);

    $this->post(route('jobs.store'), jobPayload(['brand_id' => $inactive->id]))
        ->assertSessionHasErrors('brand_id');
});

test('the office edits a job and its appliances', function () {
    $job = ServiceJob::factory()->for($this->property)->withAppliances([$this->washer])
        ->create(['brand_id' => $this->brand->id]);

    $this->get(route('jobs.edit', $job))
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/form')
            ->where('job.appliance_ids', [$this->washer->id])
            ->where('customer.id', $this->customer->id));

    $this->put(route('jobs.update', $job), jobPayload([
        'job_type' => 'warranty',
        'appliance_ids' => [$this->dryer->id],
        'new_appliances' => [['type' => 'dishwasher']],
    ]))->assertRedirect(route('jobs.show', $job));

    inCompany($this->company, function () use ($job) {
        $job->refresh();

        expect($job->job_type->value)->toBe('warranty')
            ->and($job->appliances()->pluck('type')->map->value->sort()->values()->all())->toBe(['dishwasher', 'dryer'])
            ->and($job->customer_id)->toBe($this->customer->id);
    });
});

test('the customer of a job cannot be changed by editing', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    $other = Customer::factory()->for($this->company)->create();
    $otherProperty = Property::factory()->for($other)->create();

    $this->put(route('jobs.update', $job), jobPayload(['customer_id' => $other->id, 'property_id' => $otherProperty->id, 'appliance_ids' => []]))
        ->assertSessionHasErrors('property_id');

    expect($job->fresh()->customer_id)->toBe($this->customer->id);
});

test('a job can be deleted and the deletion is audited', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);

    $this->delete(route('jobs.destroy', $job))->assertRedirect(route('jobs.index'));

    expect(ServiceJob::withoutCompanyScope()->withTrashed()->find($job->id)->trashed())->toBeTrue()
        ->and(AuditLog::where('action', 'job.deleted')->sole()->auditable_id)->toBe($job->id);
});

test('customers and properties with jobs cannot be deleted', function () {
    ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);

    $this->delete(route('customers.destroy', $this->customer))->assertRedirect(route('customers.show', $this->customer));
    $this->delete(route('properties.destroy', $this->property))->assertRedirect(route('customers.show', $this->customer));

    expect($this->customer->fresh()->trashed())->toBeFalse()
        ->and($this->property->fresh()->trashed())->toBeFalse();
});

test('the job page shows customer, address, appliances, visits and history', function () {
    $job = ServiceJob::factory()->for($this->property)->withAppliances([$this->washer])->withVisit($this->tech)
        ->create(['brand_id' => $this->brand->id]);
    inCompany($this->company, fn () => JobStatusChange::create(['service_job_id' => $job->id, 'to_status' => 'new', 'user_id' => $this->owner->id]));

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/show')
            ->where('job.number', $job->number)
            ->where('job.customer.display_name', 'Jane Cooper')
            ->where('job.customer.phones.0.number', '+16045550101')
            ->where('job.property.line1', '8450 128 St')
            ->where('job.appliances.0.id', $this->washer->id)
            ->where('job.visits.0.assignees.0.name', 'Tom Tech')
            ->where('job.visits.0.is_mine', false)
            ->has('job.history', 1)
            ->where('otherAppliances.0.id', $this->dryer->id)
            ->where('myVisitId', null)
            ->where('can.update', true)
            ->has('statusOptions', 8)
            ->has('assignableUsers', 2));
});

test('the new job form can start from a customer', function () {
    $this->get(route('jobs.create', ['customer_id' => $this->customer->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/form')
            ->where('customer.id', $this->customer->id)
            ->where('customer.properties.0.appliances', fn ($appliances) => count($appliances) === 2)
            ->where('brands.0.label', 'Doctor Appliance'));
});

test('the customer lookup finds customers by phone with their addresses and appliances', function () {
    $this->getJson(route('jobs.lookup', ['search' => '6045550101']))
        ->assertOk()
        ->assertJsonPath('customers.0.id', $this->customer->id)
        ->assertJsonPath('customers.0.properties.0.id', $this->property->id)
        ->assertJsonCount(2, 'customers.0.properties.0.appliances');
});

test('the job list can be searched and filtered', function () {
    $scheduled = ServiceJob::factory()->for($this->property)->withAppliances([$this->washer])
        ->withVisit($this->tech, ['scheduled_start' => '2030-02-01 17:00:00', 'scheduled_end' => '2030-02-01 19:00:00'])
        ->create(['brand_id' => $this->brand->id]);
    $other = Customer::factory()->for($this->company)->create(['first_name' => 'Zed']);
    $new = ServiceJob::factory()->for(Property::factory()->for($other))->create(['brand_id' => $this->brand->id]);

    $ids = fn (array $query) => collect(
        $this->get(route('jobs.index', $query))->viewData('page')['props']['jobs']['data'],
    )->pluck('id')->all();

    expect($ids([]))->toBe([$new->id, $scheduled->id])
        ->and($ids(['status' => 'scheduled']))->toBe([$scheduled->id])
        ->and($ids(['status' => 'open']))->toBe([$new->id, $scheduled->id])
        ->and($ids(['technician' => $this->tech->id]))->toBe([$scheduled->id])
        ->and($ids(['from' => '2030-02-01', 'to' => '2030-02-01']))->toBe([$scheduled->id])
        ->and($ids(['from' => '2030-02-02']))->toBe([])
        ->and($ids(['search' => '#'.$new->number]))->toBe([$new->id])
        ->and($ids(['search' => 'WM3900']))->toBe([$scheduled->id])
        ->and($ids(['search' => '604 555 0101']))->toBe([$scheduled->id])
        ->and($ids(['search' => 'Zed']))->toBe([$new->id]);
});

test('the customer card lists the customer\'s jobs', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);

    $this->get(route('customers.show', $this->customer))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $job->id)
            ->where('canCreateJob', true));
});

test('an admin limited to some brands only sees and creates jobs of those brands', function () {
    $otherBrand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Duct Works']);
    $admin = memberOf($this->company, UserRole::Admin);
    DB::table('brand_user')->insert([
        'company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'user_id' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $mine = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    $theirs = ServiceJob::factory()->for($this->property)->create(['brand_id' => $otherBrand->id]);

    $this->actingAs($admin);

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.total', 1)
            ->where('jobs.data.0.id', $mine->id)
            ->where('brands', [['value' => (string) $this->brand->id, 'label' => 'Doctor Appliance']]));

    $this->get(route('jobs.show', $mine))->assertOk();
    $this->get(route('jobs.show', $theirs))->assertForbidden();
    $this->put(route('jobs.update', $theirs), jobPayload(['brand_id' => $otherBrand->id]))->assertForbidden();
    $this->post(route('jobs.store'), jobPayload(['brand_id' => $otherBrand->id]))->assertSessionHasErrors('brand_id');
    $this->post(route('jobs.store'), jobPayload())->assertRedirect();

    expect(inCompany($this->company, fn () => ServiceJob::where('brand_id', $this->brand->id)->count()))->toBe(2);
});

test('job types follow the company vertical', function () {
    $this->post(route('jobs.store'), jobPayload(['job_type' => 'mounting']))->assertSessionHasErrors('job_type');

    $this->company->update(['vertical' => 'handyman']);

    $this->get(route('jobs.create'))->assertInertia(fn (Assert $page) => $page
        ->where('jobTypes', collect(Vertical::Handyman->jobTypeOptions())->all()));
    $this->post(route('jobs.store'), jobPayload(['job_type' => 'mounting', 'appliance_ids' => []]))->assertSessionHasNoErrors();
    $this->post(route('jobs.store'), jobPayload(['job_type' => 'vent_cleaning']))->assertSessionHasErrors('job_type');
});
