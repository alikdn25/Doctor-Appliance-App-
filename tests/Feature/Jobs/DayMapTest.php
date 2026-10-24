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
use App\Models\User;
use App\Support\Routing\RoutePlanner;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Map tab (owner's task, October 24): the day's jobs as numbered pins, a technician sees only their own, the
 * day's route, a better order that keeps the windows, Apply saves it, the office moves a job to another person.
 */
beforeEach(function () {
    // No daylight saving: local = UTC−6.
    $this->company = Company::factory()->create(['timezone' => 'America/Regina']);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Owner']);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tom Tech']);
    $this->other = memberOf($this->company, UserRole::Technician, ['name' => 'Olga Other']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);

    // Wednesday 2030-06-12, 07:00 local.
    $this->travelTo('2030-06-12 13:00:00');
});

/**
 * A job at a place (lat, lng) with a visit in a local window.
 *
 * @param  list<User>  $people
 */
function mapVisit(string $localStart, array $people, array $place = [50.45, -104.6], int $window = 120, array $visit = []): JobVisit
{
    $property = Property::factory()->for(test()->customer)->create([
        'line1' => fake()->streetAddress(), 'city' => 'Regina', 'latitude' => $place[0], 'longitude' => $place[1],
    ]);
    $start = CarbonImmutable::parse($localStart, 'America/Regina')->utc();
    $job = ServiceJob::factory()->for($property)->create(['brand_id' => test()->brand->id, 'status' => JobStatus::Scheduled]);

    return JobVisit::factory()->for($job, 'job')->assignedTo($people)->create([
        'scheduled_start' => $start,
        'scheduled_end' => $start->addMinutes($window),
        'estimated_duration_minutes' => 60,
        ...$visit,
    ]);
}

function mapProps(array $query = []): array
{
    return test()->get(route('map', $query))->assertOk()->viewData('page')['props'];
}

test('a technician sees only their own jobs on the map; the office sees everyone\'s', function () {
    $mine = mapVisit('2030-06-12 09:00', [$this->tech]);
    $theirs = mapVisit('2030-06-12 10:00', [$this->other]);
    $nobody = mapVisit('2030-06-12 11:00', []);
    mapVisit('2030-06-13 09:00', [$this->tech]);

    $this->actingAs($this->tech);
    $props = mapProps();
    expect(collect($props['stops'])->pluck('id')->all())->toBe([$mine->id])
        ->and($props['canManage'])->toBeFalse()
        ->and($props['people'])->toBe([['id' => $this->tech->id, 'name' => 'Tom Tech']]);
    // Asking for another person changes nothing.
    expect(collect(mapProps(['person' => (string) $this->other->id])['stops'])->pluck('id')->all())->toBe([$mine->id]);
    expect($this->get(route('map'))->viewData('page')['props']['auth']['can']['viewMap'])->toBeTrue();

    $this->actingAs($this->owner);
    expect(collect(mapProps()['stops'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mine->id, $theirs->id, $nobody->id])->sort()->values()->all())
        ->and(collect(mapProps(['person' => (string) $this->other->id])['stops'])->pluck('id')->all())->toBe([$theirs->id])
        ->and(collect(mapProps(['person' => 'unassigned'])['stops'])->pluck('id')->all())->toBe([$nobody->id]);
});

test('pins are numbered in the day\'s order and carry the card\'s details', function () {
    $second = mapVisit('2030-06-12 13:00', [$this->tech]);
    $first = mapVisit('2030-06-12 09:00', [$this->tech]);

    $this->actingAs($this->tech)->get(route('map', ['date' => '2030-06-12']))->assertInertia(fn (Assert $page) => $page
        ->component('map/index')
        ->where('date', '2030-06-12')
        ->where('today', '2030-06-12')
        ->where('stops.0.id', $first->id)
        ->where('stops.0.position', 1)
        ->where('stops.0.start_time', '09:00')
        ->where('stops.0.end_time', '11:00')
        ->where('stops.0.customer', 'Jane Cooper')
        ->where('stops.0.job_status', 'scheduled')
        ->where('stops.0.person_id', $this->tech->id)
        ->where('stops.0.coordinates', ['lat' => 50.45, 'lng' => -104.6])
        ->where('stops.1.id', $second->id)
        ->where('stops.1.position', 2));
});

test('Apply saves the new order: the map, the calendar and My Jobs follow it', function () {
    $a = mapVisit('2030-06-12 09:00', [$this->tech], window: 240);
    $b = mapVisit('2030-06-12 09:30', [$this->tech], window: 240);
    $c = mapVisit('2030-06-12 10:00', [$this->tech], window: 240);

    $this->actingAs($this->tech)->post(route('map.order'), ['visit_ids' => [$c->id, $a->id, $b->id]])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(JobVisit::query()->withoutGlobalScopes()->whereKey([$a->id, $b->id, $c->id])->orderBy('route_position')->pluck('id')->all())
        ->toBe([$c->id, $a->id, $b->id]);
    expect(collect(mapProps()['stops'])->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
    $mine = $this->get(route('jobs.mine'))->viewData('page')['props']['visits'];
    expect(collect($mine)->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);

    $this->actingAs($this->owner);
    $calendar = $this->get(route('calendar'))->viewData('page')['props']['visits'];
    expect(collect($calendar)->firstWhere('id', $c->id)['route_position'])->toBe(1);

    // A visit moved to another time goes by its window again.
    inCompany($this->company, fn () => $a->fresh()->update(['scheduled_start' => $a->scheduled_start->addMinutes(15)]));
    expect(inCompany($this->company, fn () => $a->fresh()->route_position))->toBeNull();
});

test('a technician cannot put in order someone else\'s jobs, nor jobs of two people or another company', function () {
    $foreign = Company::factory()->create();
    $stranger = memberOf($foreign, UserRole::Owner);
    $mine = mapVisit('2030-06-12 09:00', [$this->tech]);
    $theirs = mapVisit('2030-06-12 10:00', [$this->other]);

    $this->actingAs($this->tech)->post(route('map.order'), ['visit_ids' => [$theirs->id, $mine->id]])->assertNotFound();
    $this->actingAs($this->owner)->post(route('map.order'), ['visit_ids' => [$theirs->id, $mine->id]])
        ->assertSessionHasErrors('visit_ids');

    $this->actingAs($stranger)->post(route('map.order'), ['visit_ids' => [$mine->id]])->assertNotFound();
    $this->actingAs($stranger)->postJson(route('map.plan'), ['visit_ids' => [$mine->id]])->assertNotFound();
    expect(collect($this->actingAs($stranger)->get(route('map', ['date' => '2030-06-12']))->viewData('page')['props']['stops']))->toBeEmpty();

    expect(JobVisit::query()->withoutGlobalScopes()->whereNotNull('route_position')->count())->toBe(0);
});

test('the office moves a job to another technician from the map, and it stays there', function () {
    $visit = mapVisit('2030-06-12 09:00', [$this->tech]);
    $this->actingAs($this->tech)->post(route('map.order'), ['visit_ids' => [$visit->id]]);

    $this->actingAs($this->owner)->post(route('visits.transfer'), [
        'visit_ids' => [$visit->id], 'from_user_id' => $this->tech->id, 'to_user_id' => $this->other->id,
    ])->assertSessionHasNoErrors();

    $props = mapProps(['person' => (string) $this->other->id]);
    expect(collect($props['stops'])->pluck('id')->all())->toBe([$visit->id])
        ->and($props['stops'][0]['assignees'])->toBe([['id' => $this->other->id, 'name' => 'Olga Other']])
        ->and($props['stops'][0]['route_position'])->toBeNull();
    $this->actingAs($this->tech);
    expect(mapProps()['stops'])->toBeEmpty();

    // A technician cannot move jobs.
    $this->post(route('visits.transfer'), [
        'visit_ids' => [$visit->id], 'from_user_id' => $this->other->id, 'to_user_id' => $this->tech->id,
    ])->assertForbidden();
});

test('Route totals the day in its order and Optimize finds a better order that keeps the windows', function () {
    // West (A), far east (B), west again (C): A → C → B drives less than A → B → C.
    $a = mapVisit('2030-06-12 09:00', [$this->tech], [50.45, -104.70], 300);
    $b = mapVisit('2030-06-12 09:00', [$this->tech], [50.45, -104.40], 300);
    $c = mapVisit('2030-06-12 09:00', [$this->tech], [50.45, -104.69], 300);

    $plan = $this->actingAs($this->tech)->postJson(route('map.plan'), ['visit_ids' => [$a->id, $b->id, $c->id]])
        ->assertOk()->json();

    expect($plan['estimated'])->toBeTrue()
        ->and($plan['current']['order'])->toBe([$a->id, $b->id, $c->id])
        ->and($plan['current']['drive_minutes'])->toBeGreaterThan(0)
        ->and($plan['current']['distance_km'])->toBeGreaterThan(0)
        ->and($plan['changed'])->toBeTrue()
        ->and($plan['best']['order'][2])->toBe($b->id)
        ->and($plan['saved_drive_minutes'])->toBeGreaterThan(0)
        ->and($plan['saved_km'])->toBeGreaterThan(0)
        ->and($plan['best']['late_minutes'])->toBe(0);

    // Road times from the map are used when they come with the request.
    $minutes = [[0, 10, 30], [10, 0, 10], [30, 10, 0]];
    $km = [[0, 5, 20], [5, 0, 5], [20, 5, 0]];
    $roads = $this->postJson(route('map.plan'), ['visit_ids' => [$a->id, $b->id, $c->id], 'minutes' => $minutes, 'km' => $km])->json();
    expect($roads['estimated'])->toBeFalse()
        ->and($roads['current']['drive_minutes'])->toBe(20)
        ->and($roads['current']['distance_km'])->toBe(10)
        ->and($roads['changed'])->toBeFalse();
});

test('the planner keeps arrival windows and leaves a job already started first', function () {
    // Two stops 30 minutes apart; the second window opens only in the afternoon.
    $stops = [
        ['id' => 1, 'open' => 13 * 60, 'close' => 15 * 60, 'service' => 60],
        ['id' => 2, 'open' => 9 * 60, 'close' => 11 * 60, 'service' => 60],
    ];
    $planner = new RoutePlanner($stops, [[0, 30], [30, 0]], [[0, 20], [20, 0]]);
    $plan = $planner->plan();
    expect($plan['best']['order'])->toBe([2, 1])
        ->and($plan['best']['late_minutes'])->toBe(0)
        ->and($plan['current']['finish'])->toBeGreaterThan(0);

    // Started (fixed) stops stay at the front even when another order would be shorter.
    $fixed = [
        ['id' => 1, 'open' => 9 * 60, 'close' => 17 * 60, 'service' => 30, 'fixed' => true],
        ['id' => 2, 'open' => 9 * 60, 'close' => 17 * 60, 'service' => 30],
        ['id' => 3, 'open' => 9 * 60, 'close' => 17 * 60, 'service' => 30],
    ];
    $minutes = [[0, 50, 10], [50, 0, 45], [10, 45, 0]];
    $plan = (new RoutePlanner($fixed, $minutes, $minutes))->plan();
    expect($plan['best']['order'])->toBe([1, 3, 2]);

    // Many stops: still an order of all of them, without lateness when one exists.
    $many = collect(range(1, 12))->map(fn (int $i) => ['id' => $i, 'open' => 8 * 60, 'close' => 18 * 60, 'service' => 20])->all();
    $matrix = RoutePlanner::estimate(collect(range(1, 12))->map(fn (int $i) => ['lat' => 50.4, 'lng' => -104.6 + ($i % 2 ? $i : -$i) / 100])->all());
    $plan = (new RoutePlanner($many, $matrix['minutes'], $matrix['km']))->plan();
    expect($plan['best']['order'])->toHaveCount(12)
        ->and(collect($plan['best']['order'])->sort()->values()->all())->toBe(range(1, 12))
        ->and($plan['best']['drive_minutes'])->toBeLessThanOrEqual($plan['current']['drive_minutes']);
});

test('finished and cancelled jobs are on the map but not in a route', function () {
    $done = mapVisit('2030-06-12 08:00', [$this->tech], visit: ['status' => VisitStatus::Completed]);
    mapVisit('2030-06-12 09:00', [$this->tech], visit: ['status' => VisitStatus::Cancelled]);
    $open = mapVisit('2030-06-12 10:00', [$this->tech]);

    $this->actingAs($this->tech);
    expect(collect(mapProps()['stops'])->pluck('id')->all())->toBe([$done->id, $open->id]);
    $this->post(route('map.order'), ['visit_ids' => [$open->id, $done->id]])->assertNotFound();
});
