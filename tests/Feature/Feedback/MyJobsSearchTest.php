<?php

use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2030-06-12 18:00:00');
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->withPhone('778-300-2070')->create(['first_name' => 'Sasha', 'last_name' => 'M']);
    $property = Property::factory()->for($customer)->create();
    $this->job = ServiceJob::factory()->for($property)->create(['brand_id' => $this->brand->id]);
    $appliance = inCompany($this->company, fn () => Appliance::factory()->for($property)->create(['manufacturer' => 'Blomberg', 'type' => 'dishwasher']));
    inCompany($this->company, fn () => $this->job->appliances()->attach($appliance));
    // A visit next week: on the Upcoming tab, not Today.
    $this->upcoming = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->addDays(7), 'scheduled_end' => now()->addDays(7)->addHours(2)]);

    $other = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Other'])))
        ->create(['brand_id' => $this->brand->id]);
    JobVisit::factory()->for($other, 'job')->assignedTo($this->tech)->create(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
    $this->actingAs($this->tech);
});

test('a search covers every tab and updates the tab counts', function (string $term) {
    $this->get(route('jobs.mine', ['tab' => 'today', 'search' => $term]))->assertInertia(fn (Assert $page) => $page
        ->where('search', $term)
        ->where('counts.today', 0)
        ->where('counts.upcoming', 1)
        ->has('visits', 0));

    $this->get(route('jobs.mine', ['tab' => 'upcoming', 'search' => $term]))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.id', $this->upcoming->id));
})->with(['778-300-2070', '(778) 300 2070', 'Blomberg', 'dishwasher', 'Sasha']);

test('without a search every tab is counted as before', function () {
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('counts.today', 1)
        ->where('counts.upcoming', 1)
        ->has('visits', 1));
});

test('an open visit from an earlier day stays in Today and is not repeated in Recent', function () {
    $visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->subDay(), 'scheduled_end' => now()->subDay()->addHours(2), 'status' => VisitStatus::InProgress]);

    $this->get(route('jobs.mine', ['tab' => 'today']))->assertInertia(fn (Assert $page) => $page
        ->where('visits', fn ($visits) => collect($visits)->contains('id', $visit->id)));
    $this->get(route('jobs.mine', ['tab' => 'recent']))->assertInertia(fn (Assert $page) => $page
        ->where('visits', fn ($visits) => ! collect($visits)->contains('id', $visit->id))
        ->where('counts.recent', 0));
});

test('the customer list finds a customer by appliance brand', function () {
    $this->actingAs(memberOf($this->company, UserRole::Admin));

    $this->get(route('customers.index', ['search' => 'blomberg']))->assertInertia(fn (Assert $page) => $page
        ->where('customers.data.0.display_name', fn ($name) => str_contains($name, 'Sasha')));
});
