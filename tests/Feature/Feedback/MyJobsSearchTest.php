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
    // A visit next week: on its own day, not Today.
    $this->upcoming = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->addDays(7), 'scheduled_end' => now()->addDays(7)->addHours(2)]);

    $other = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Other'])))
        ->create(['brand_id' => $this->brand->id]);
    JobVisit::factory()->for($other, 'job')->assignedTo($this->tech)->create(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
    $this->actingAs($this->tech);
});

test('a search applies to the chosen day', function (string $term) {
    $day = $this->upcoming->scheduled_start->timezone('America/Vancouver')->toDateString();

    $this->get(route('jobs.mine', ['search' => $term]))->assertInertia(fn (Assert $page) => $page
        ->where('search', $term)
        ->has('visits', 0));

    $this->get(route('jobs.mine', ['date' => $day, 'search' => $term]))->assertInertia(fn (Assert $page) => $page
        ->where('date', $day)
        ->where('visits.0.id', $this->upcoming->id));
})->with(['778-300-2070', '(778) 300 2070', 'Blomberg', 'dishwasher', 'Sasha']);

test('without a search the day shows its own jobs', function () {
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'day')
        ->where('date', '2030-06-12')
        ->where('today', '2030-06-12')
        ->has('visits', 1));
});

test('an open visit from an earlier day stays in Today, not on that earlier day', function () {
    $visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->subDay(), 'scheduled_end' => now()->subDay()->addHours(2), 'status' => VisitStatus::InProgress]);

    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('visits', fn ($visits) => collect($visits)->contains('id', $visit->id)));
    $this->get(route('jobs.mine', ['tab' => 'completed']))->assertInertia(fn (Assert $page) => $page
        ->where('visits', fn ($visits) => ! collect($visits)->contains('id', $visit->id)));
});

test('the customer list finds a customer by appliance brand', function () {
    $this->actingAs(memberOf($this->company, UserRole::Admin));

    $this->get(route('customers.index', ['search' => 'blomberg']))->assertInertia(fn (Assert $page) => $page
        ->where('customers.data.0.display_name', fn ($name) => str_contains($name, 'Sasha')));
});

test('an owner whose day is empty is told how many jobs others have that day', function () {
    $owner = memberOf($this->company, UserRole::Owner);
    $day = $this->upcoming->scheduled_start->timezone('America/Vancouver')->toDateString();
    // Not assigned to anyone yet: still counted.
    JobVisit::factory()->for($this->job, 'job')->create(['scheduled_start' => $this->upcoming->scheduled_start->addHours(3), 'scheduled_end' => $this->upcoming->scheduled_start->addHours(4)]);
    JobVisit::factory()->for($this->job, 'job')->create(['scheduled_start' => $this->upcoming->scheduled_start->addHour(), 'scheduled_end' => $this->upcoming->scheduled_start->addHours(2), 'status' => VisitStatus::Cancelled]);

    $this->actingAs($owner)->get(route('jobs.mine', ['date' => $day]))->assertInertia(fn (Assert $page) => $page
        ->has('visits', 0)
        ->where('othersOnDay', 2));

    // Another company's visits that day are not counted.
    $foreign = Company::factory()->create(['timezone' => 'America/Vancouver']);
    inCompany($foreign, function () use ($foreign) {
        $foreignJob = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($foreign)->withPhone('604-555-0199')->create()))
            ->create(['brand_id' => Brand::factory()->create(['company_id' => $foreign->id])->id]);
        JobVisit::factory()->for($foreignJob, 'job')->create(['scheduled_start' => $this->upcoming->scheduled_start, 'scheduled_end' => $this->upcoming->scheduled_end]);
    });

    $this->get(route('jobs.mine', ['date' => $day]))->assertInertia(fn (Assert $page) => $page->where('othersOnDay', 2));
});

test('a technician is not shown other people\'s jobs count', function () {
    $day = $this->upcoming->scheduled_start->timezone('America/Vancouver')->toDateString();
    JobVisit::factory()->for($this->job, 'job')->create(['scheduled_start' => $this->upcoming->scheduled_start, 'scheduled_end' => $this->upcoming->scheduled_end]);

    $this->get(route('jobs.mine', ['date' => $day]))->assertInertia(fn (Assert $page) => $page->where('othersOnDay', 0));
});
