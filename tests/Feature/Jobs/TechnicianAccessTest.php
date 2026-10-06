<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->otherTech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);

    // A job assigned to the technician.
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Mine', 'last_name' => 'Owner']);
    $this->property = Property::factory()->for($this->customer)->create();
    $this->secondProperty = Property::factory()->for($this->customer)->create(['is_primary' => false, 'line1' => '9 Rental Rd']);
    $this->appliance = Appliance::factory()->for($this->property)->create(['type' => 'washer', 'manufacturer' => 'LG', 'model_number' => 'OLD1']);
    $this->spare = Appliance::factory()->for($this->property)->create(['type' => 'dryer']);
    $this->job = ServiceJob::factory()->for($this->property)->withAppliances([$this->appliance])->withVisit($this->tech)
        ->create(['brand_id' => $this->brand->id]);

    // A job of another technician, for another customer.
    $this->otherCustomer = Customer::factory()->for($this->company)->create(['first_name' => 'Theirs']);
    $this->otherProperty = Property::factory()->for($this->otherCustomer)->create();
    $this->otherAppliance = Appliance::factory()->for($this->otherProperty)->create();
    $this->otherJob = ServiceJob::factory()->for($this->otherProperty)->withAppliances([$this->otherAppliance])
        ->withVisit($this->otherTech)->create(['brand_id' => $this->brand->id]);

    $this->actingAs($this->tech);
});

test('technicians are sent from the job list to my jobs', function () {
    $this->get(route('jobs.index'))->assertRedirect(route('jobs.mine'));
});

test('my jobs lists only the user\'s own visits by day', function () {
    $this->travelTo('2030-06-12 18:00:00');
    $today = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->subHour(), 'scheduled_end' => now()->addHour()]);
    JobVisit::factory()->for($this->otherJob, 'job')->assignedTo($this->otherTech)
        ->create(['scheduled_start' => now(), 'scheduled_end' => now()->addHour()]);
    $past = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->subDays(3), 'scheduled_end' => now()->subDays(3)->addHour(), 'status' => VisitStatus::Completed]);
    $upcoming = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)
        ->whereKeyNot([$today->id, $past->id])->sole();
    $upcoming->forceFill(['scheduled_start' => now()->addDays(2), 'scheduled_end' => now()->addDays(2)->addHour()])->saveQuietly();

    $ids = fn (string $tab) => collect($this->get(route('jobs.mine', ['tab' => $tab]))->viewData('page')['props']['visits'])
        ->pluck('id')->all();

    expect($ids('today'))->toBe([$today->id])
        ->and($ids('upcoming'))->toBe([$upcoming->id])
        ->and($ids('recent'))->toBe([$past->id]);

    $this->get(route('jobs.mine'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/mine')
            ->where('tab', 'today')
            ->where('visits.0.job.customer', 'Mine Owner')
            ->where('visits.0.job.phone', '+16045550101')
            ->where('visits.0.job.appliances', ['LG Washer']));
});

test('a visit already under way stays on today\'s list', function () {
    $this->travelTo('2030-06-12 18:00:00');
    $visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)->create([
        'scheduled_start' => now()->subDays(2), 'scheduled_end' => now()->subDays(2)->addHour(), 'status' => VisitStatus::InProgress,
    ]);

    expect(collect($this->get(route('jobs.mine'))->viewData('page')['props']['visits'])->pluck('id')->all())
        ->toContain($visit->id);
});

test('a technician opens only their own jobs', function () {
    $this->get(route('jobs.show', $this->job))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.viewCustomer', true)->where('assignableUsers', []));
    $this->get(route('jobs.show', $this->otherJob))->assertForbidden();
});

test('a technician cannot manage jobs, visits or statuses', function () {
    $visit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();

    $this->get(route('jobs.create'))->assertForbidden();
    $this->post(route('jobs.store'), ['brand_id' => $this->brand->id])->assertForbidden();
    $this->getJson(route('jobs.lookup', ['search' => 'Mine']))->assertForbidden();
    $this->get(route('jobs.edit', $this->job))->assertForbidden();
    $this->put(route('jobs.update', $this->job), ['brand_id' => $this->brand->id])->assertForbidden();
    $this->delete(route('jobs.destroy', $this->job))->assertForbidden();
    $this->put(route('jobs.status', $this->job), ['status' => 'cancelled'])->assertForbidden();
    $this->post(route('visits.store', $this->job), ['date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '10:00'])->assertForbidden();
    $this->put(route('visits.update', $visit), ['date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '10:00'])->assertForbidden();
    $this->delete(route('visits.destroy', $visit))->assertForbidden();

    expect($this->job->fresh())->status->toBe(JobStatus::Scheduled)->trashed()->toBeFalse();
});

test('a technician cannot press buttons on someone else\'s visit', function () {
    $otherVisit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->otherJob->id)->sole();

    $this->post(route('visits.on-my-way', $otherVisit))->assertForbidden();
    $this->post(route('visits.start', $otherVisit))->assertForbidden();
    $this->post(route('visits.finish', $otherVisit), ['outcome' => 'completed'])->assertForbidden();

    // Same job, but a visit assigned to someone else.
    $notMine = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->otherTech)->create();
    $this->post(route('visits.start', $notMine))->assertForbidden();

    expect($this->otherJob->fresh()->status)->toBe(JobStatus::Scheduled);
});

test('a technician sees the customer of their job, limited to that job\'s address', function () {
    $this->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customer.properties', fn ($properties) => collect($properties)->pluck('id')->all() === [$this->property->id])
            ->has('customer.properties.0.appliances', 2)
            ->where('jobs.0.id', $this->job->id)
            ->has('jobs', 1)
            ->where('canUpdate', false)
            ->where('canDelete', false)
            ->where('canCreateJob', false));

    $this->get(route('customers.show', $this->otherCustomer))->assertForbidden();
    $this->get(route('customers.index'))->assertForbidden();
    $this->get(route('customers.edit', $this->customer))->assertForbidden();
    $this->put(route('customers.update', $this->customer), ['type' => 'residential', 'first_name' => 'X'])->assertForbidden();
    $this->post(route('properties.store', $this->customer), ['line1' => 'X', 'city' => 'Y', 'country' => 'CA'])->assertForbidden();
    $this->put(route('properties.update', $this->property), ['line1' => 'X', 'city' => 'Y', 'country' => 'CA'])->assertForbidden();
});

test('a technician sees appliances at their job\'s address, but cannot use the office appliance forms', function () {
    $this->get(route('appliances.show', $this->appliance))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canUpdate', false)->where('customer.can_view', true));
    $this->get(route('appliances.show', $this->spare))->assertOk();
    $this->get(route('appliances.show', $this->otherAppliance))->assertForbidden();

    $this->put(route('appliances.update', $this->appliance), ['type' => 'dryer'])->assertForbidden();
    $this->delete(route('appliances.destroy', $this->appliance))->assertForbidden();
    $this->post(route('appliances.store', $this->property), ['type' => 'dryer'])->assertForbidden();
});

test('a technician corrects the type, manufacturer, model and serial number of an appliance on their job', function () {
    $this->put(route('jobs.appliances.update', [$this->job, $this->appliance]), [
        'manufacturer' => 'Samsung',
        'model_number' => 'wf45 ',
        'serial_number' => 'abc123',
        'type' => 'dryer',
        'notes' => 'Ignored',
    ])->assertRedirect();

    expect($this->appliance->fresh())
        ->manufacturer->toBe('Samsung')
        ->model_number->toBe('WF45')
        ->serial_number->toBe('ABC123')
        ->type->value->toBe('dryer')
        ->notes->toBeNull();
});

test('a technician cannot edit appliances that are not on their job', function () {
    // At the address, but not linked to the job.
    $this->put(route('jobs.appliances.update', [$this->job, $this->spare]), ['model_number' => 'X'])->assertNotFound();
    // Another technician's job.
    $this->put(route('jobs.appliances.update', [$this->otherJob, $this->otherAppliance]), ['model_number' => 'X'])->assertForbidden();
    // Linked to another job, through the technician's own job.
    $this->put(route('jobs.appliances.update', [$this->job, $this->otherAppliance]), ['model_number' => 'X'])->assertNotFound();

    expect($this->spare->fresh()->model_number)->not->toBe('X')
        ->and($this->otherAppliance->fresh()->model_number)->not->toBe('X');
});

test('a technician adds a new appliance or one already at the address to their job', function () {
    $this->post(route('jobs.appliances.store', $this->job), [
        'type' => 'dishwasher', 'manufacturer' => 'Bosch', 'model_number' => 'she3', 'serial_number' => 'fd123',
    ])->assertRedirect();
    $this->post(route('jobs.appliances.store', $this->job), ['appliance_id' => $this->spare->id])->assertRedirect();
    $this->post(route('jobs.appliances.store', $this->job), ['appliance_id' => $this->otherAppliance->id])->assertNotFound();
    $this->post(route('jobs.appliances.store', $this->otherJob), ['type' => 'dryer'])->assertForbidden();

    inCompany($this->company, function () {
        $appliances = $this->job->appliances()->get();
        $new = $appliances->firstWhere('type.value', 'dishwasher');

        expect($appliances)->toHaveCount(3)
            ->and($new)->property_id->toBe($this->property->id)->model_number->toBe('SHE3')
            ->and($this->otherJob->appliances()->count())->toBe(1);
    });
});

test('a technician writes what was done on their own job only', function () {
    $this->put(route('jobs.tech-notes', $this->job), ['tech_notes' => 'Replaced drain pump.'])->assertRedirect();
    $this->put(route('jobs.tech-notes', $this->otherJob), ['tech_notes' => 'Hack'])->assertForbidden();

    expect($this->job->fresh()->tech_notes)->toBe('Replaced drain pump.')
        ->and($this->otherJob->fresh()->tech_notes)->toBeNull();
});

test('the appliance repair history shows all jobs, linking only the ones the technician can open', function () {
    $earlier = ServiceJob::factory()->for($this->property)->withAppliances([$this->appliance])->withVisit($this->otherTech)
        ->create(['brand_id' => $this->brand->id, 'tech_notes' => 'Cleaned filter', 'status' => JobStatus::Completed, 'completed_at' => '2029-05-01 18:00:00']);

    $this->get(route('appliances.show', $this->appliance))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history', 2)
            ->where('history.0.id', $earlier->id)
            ->where('history.0.work_done', 'Cleaned filter')
            ->where('history.0.job_type_label', 'Repair')
            ->where('history.0.can_open', false)
            ->where('history.1.id', $this->job->id)
            ->where('history.1.can_open', true)
            ->where('history.0', fn ($entry) => collect($entry)->keys()->intersect(['total', 'price', 'amount'])->isEmpty()));
});

test('an owner assigned to a visit sees it in my jobs and can work on it', function () {
    $owner = memberOf($this->company, UserRole::Owner);
    $job = ServiceJob::factory()->for($this->property)->withVisit($owner)->create(['brand_id' => $this->brand->id]);
    $visit = JobVisit::withoutCompanyScope()->where('service_job_id', $job->id)->sole();

    $this->actingAs($owner);

    expect(collect($this->get(route('jobs.mine', ['tab' => 'upcoming']))->viewData('page')['props']['visits'])->pluck('id')->all())
        ->toBe([$visit->id]);

    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->where('myVisitId', $visit->id));
    $this->post(route('visits.on-my-way', $visit))->assertRedirect();

    expect($job->fresh()->status)->toBe(JobStatus::OnTheWay);
});

test('a brand-limited admin still sees jobs they are assigned to', function () {
    $otherBrand = Brand::factory()->create(['company_id' => $this->company->id]);
    $admin = memberOf($this->company, UserRole::Admin);
    DB::table('brand_user')->insert([
        'company_id' => $this->company->id, 'brand_id' => $otherBrand->id, 'user_id' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $job = ServiceJob::factory()->for($this->property)->withVisit($admin)->create(['brand_id' => $this->brand->id]);

    $this->actingAs($admin);

    $this->get(route('jobs.show', $job))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false));
    $this->get(route('jobs.show', $this->job))->assertForbidden();
});

test('a deactivated technician loses access to their jobs', function () {
    DB::table('company_user')->where('user_id', $this->tech->id)->update(['is_active' => false]);

    $response = $this->get(route('jobs.show', $this->job));

    expect($response->status())->not->toBe(200);
    $this->post(route('visits.start', JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole()));

    expect($this->job->fresh()->status)->toBe(JobStatus::Scheduled);
});
