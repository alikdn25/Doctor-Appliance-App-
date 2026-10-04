<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2030-06-12 18:00:00');
    $this->company = Company::factory()->create(['sms_mode' => 'technician_phone']);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Card', 'last_name' => 'Owner']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $brand->id]);
    $this->visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
    $this->actingAs($this->tech);
});

test('a scheduled card offers On my way with the text for the technician\'s phone', function () {
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.id', $this->visit->id)
        ->where('visits.0.can_work', true)
        ->where('visits.0.on_my_way_sms.to', '+16045550101')
        ->where('visits.0.on_my_way_sms.body', fn ($body) => is_string($body) && $body !== ''));
});

test('no phone text is prepared when the company texts automatically or the visit is under way', function () {
    $this->company->update(['sms_mode' => 'automatic']);
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('visits.0.on_my_way_sms', null));

    $this->company->update(['sms_mode' => 'technician_phone']);
    $this->visit->forceFill(['status' => VisitStatus::InProgress])->saveQuietly();
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('visits.0.on_my_way_sms', null));
});

test('cards of a closed job offer no field action', function () {
    $this->job->forceFill(['status' => JobStatus::Cancelled])->saveQuietly();
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('visits.0.can_work', false));
});

test('Finish visit from My jobs opens the finish dialog on the job page', function () {
    $this->get(route('jobs.show', [$this->job, 'finish' => 1]))->assertInertia(fn (Assert $page) => $page->where('openFinish', true));
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('openFinish', false));
});
