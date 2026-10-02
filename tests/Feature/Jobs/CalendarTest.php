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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // A timezone without daylight saving keeps local ↔ UTC fixed: UTC−6.
    $this->company = Company::factory()->create(['timezone' => 'America/Regina', 'travel_buffer_minutes' => 30]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Owner']);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tom Tech']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '1 Main St', 'city' => 'Regina']);

    // Wednesday 2030-06-12, 10:00 local.
    $this->travelTo('2030-06-12 16:00:00');
    $this->actingAs($this->owner);
});

/**
 * A job with a visit at a local time ("2030-06-12 09:00") in the company's timezone.
 */
function visitAt(string $localStart, int $minutes = 120, array $people = [], array $visit = [], array $job = []): JobVisit
{
    $start = CarbonImmutable::parse($localStart, 'America/Regina')->utc();
    $serviceJob = ServiceJob::factory()->for(test()->property)->create(['brand_id' => test()->brand->id, 'status' => JobStatus::Scheduled, ...$job]);

    return JobVisit::factory()->for($serviceJob, 'job')->assignedTo($people)->create([
        'scheduled_start' => $start,
        'scheduled_end' => $start->addMinutes($minutes),
        'estimated_duration_minutes' => 60,
        ...$visit,
    ]);
}

function calendarProps(array $query = []): array
{
    return test()->get(route('calendar', $query))->assertOk()->viewData('page')['props'];
}

test('the day view shows lanes per person and the day\'s visits in local time', function () {
    $visit = visitAt('2030-06-12 09:00', 120, [test()->tech]);
    visitAt('2030-06-13 09:00', 120, [test()->tech]);

    $this->get(route('calendar'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/index')
            ->where('view', 'day')
            ->where('date', '2030-06-12')
            ->where('days', ['2030-06-12'])
            ->where('previous', '2030-06-11')
            ->where('next', '2030-06-13')
            ->where('lanes', [
                ['id' => $this->owner->id, 'name' => 'Alex Owner', 'inactive' => false],
                ['id' => $this->tech->id, 'name' => 'Tom Tech', 'inactive' => false],
                ['id' => null, 'name' => null, 'inactive' => false],
            ])
            ->has('visits', 1)
            ->where('visits.0.id', $visit->id)
            ->where('visits.0.date', '2030-06-12')
            ->where('visits.0.start_time', '09:00')
            ->where('visits.0.start_minutes', 540)
            ->where('visits.0.end_minutes', 660)
            ->where('visits.0.on_site_minutes', 120)
            ->where('visits.0.assignee_ids', [$this->tech->id])
            ->where('visits.0.job.customer', 'Jane Cooper')
            ->where('visits.0.movable', true)
            ->where('visits.0.conflict', false)
            ->where('travelBuffer', 30));
});

test('the week view runs Monday to Sunday', function () {
    visitAt('2030-06-10 09:00');
    visitAt('2030-06-16 18:00');
    visitAt('2030-06-17 09:00');

    $props = calendarProps(['view' => 'week', 'date' => '2030-06-12']);

    expect($props['days'])->toBe(['2030-06-10', '2030-06-11', '2030-06-12', '2030-06-13', '2030-06-14', '2030-06-15', '2030-06-16'])
        ->and(collect($props['visits'])->pluck('date')->all())->toBe(['2030-06-10', '2030-06-16'])
        ->and($props['previous'])->toBe('2030-06-05')
        ->and($props['next'])->toBe('2030-06-19');
});

test('visible hours follow business hours and widen for early or late visits', function () {
    expect(calendarProps()['hours'])->toBe(['start' => 8, 'end' => 17]);

    visitAt('2030-06-12 06:30', 60);
    visitAt('2030-06-12 18:00', 90);

    expect(calendarProps()['hours'])->toBe(['start' => 6, 'end' => 20]);
});

test('cancelled visits are hidden and started ones cannot be moved', function () {
    visitAt('2030-06-12 09:00', visit: ['status' => VisitStatus::Cancelled]);
    $started = visitAt('2030-06-12 11:00', visit: ['status' => VisitStatus::InProgress]);
    $onHold = visitAt('2030-06-12 13:00', job: ['status' => JobStatus::OnHold]);

    $visits = collect(calendarProps()['visits'])->keyBy('id');

    expect($visits->keys()->sort()->values()->all())->toBe(collect([$started->id, $onHold->id])->sort()->values()->all())
        ->and($visits[$started->id]['movable'])->toBeFalse()
        ->and($visits[$onHold->id]['movable'])->toBeFalse();
});

test('visits closer than the travel buffer for the same person are flagged', function () {
    $first = visitAt('2030-06-12 09:00', 60, [test()->tech]);
    $tooClose = visitAt('2030-06-12 10:15', 60, [test()->tech]);
    $fine = visitAt('2030-06-12 12:00', 60, [test()->tech]);
    $otherPerson = visitAt('2030-06-12 10:15', 60, [test()->owner]);

    $conflicts = collect(calendarProps()['visits'])->where('conflict', true)->pluck('id')->sort()->values()->all();

    expect($conflicts)->toBe(collect([$first->id, $tooClose->id])->sort()->values()->all())
        ->and($conflicts)->not->toContain($fine->id)
        ->and($conflicts)->not->toContain($otherPerson->id);

    $this->company->update(['travel_buffer_minutes' => 0]);

    expect(collect(calendarProps()['visits'])->where('conflict', true)->all())->toBe([]);
});

test('a long estimated duration counts as time on site', function () {
    $long = visitAt('2030-06-12 09:00', 60, [test()->tech], ['estimated_duration_minutes' => 180]);
    $next = visitAt('2030-06-12 11:00', 60, [test()->tech]);

    $visits = collect(calendarProps()['visits'])->keyBy('id');

    expect($visits[$long->id]['on_site_minutes'])->toBe(180)
        ->and($visits[$long->id]['conflict'])->toBeTrue()
        ->and($visits[$next->id]['conflict'])->toBeTrue();
});

test('jobs without an upcoming visit are listed to schedule', function () {
    $new = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::New]);
    $parts = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::WaitingForParts]);
    visitAt('2030-06-12 09:00');
    ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Completed]);

    expect(collect(calendarProps()['unscheduled'])->pluck('id')->all())->toBe([$new->id, $parts->id]);
});

test('dropping a job on a lane books a visit and stays on the calendar', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::New]);

    $this->from(route('calendar'))
        ->post(route('visits.store', $job), [
            'date' => '2030-06-12', 'start_time' => '13:00', 'end_time' => '15:00',
            'estimated_duration_minutes' => 60, 'assignee_ids' => [$this->tech->id], 'back' => true,
        ])
        ->assertRedirect(route('calendar'));

    $visit = inCompany($this->company, fn () => JobVisit::with('assignees')->where('service_job_id', $job->id)->sole());

    expect($job->fresh()->status)->toBe(JobStatus::Scheduled)
        ->and($visit->scheduled_start->utc()->toDateTimeString())->toBe('2030-06-12 19:00:00')
        ->and($visit->assignees->pluck('id')->all())->toBe([$this->tech->id]);
});

test('moving a visit to another time keeps its window length and people', function () {
    $visit = visitAt('2030-06-12 09:00', 90, [test()->tech]);

    $this->from(route('calendar'))
        ->put(route('visits.move', $visit), [
            'date' => '2030-06-13', 'start_time' => '14:15',
            'from_user_id' => $this->tech->id, 'to_user_id' => $this->tech->id,
        ])
        ->assertRedirect(route('calendar'));

    $visit = inCompany($this->company, fn () => JobVisit::with('assignees')->find($visit->id));

    expect($visit->scheduled_start->utc()->toDateTimeString())->toBe('2030-06-13 20:15:00')
        ->and($visit->scheduled_end->utc()->toDateTimeString())->toBe('2030-06-13 21:45:00')
        ->and($visit->estimated_duration_minutes)->toBe(60)
        ->and($visit->assignees->pluck('id')->all())->toBe([$this->tech->id]);
});

test('moving a visit to another lane swaps only the person it was dragged from', function () {
    $helper = memberOf($this->company, UserRole::Technician);
    $visit = visitAt('2030-06-12 09:00', 120, [test()->tech, $helper]);

    $this->put(route('visits.move', $visit), [
        'date' => '2030-06-12', 'start_time' => '09:00',
        'from_user_id' => $this->tech->id, 'to_user_id' => $this->owner->id,
    ])->assertRedirect();

    $ids = fn () => inCompany($this->company, fn () => JobVisit::find($visit->id)->assignees()->pluck('users.id')->sort()->values()->all());

    expect($ids())->toBe(collect([$helper->id, $this->owner->id])->sort()->values()->all());

    // To the "unassigned" lane: the person is removed.
    $this->put(route('visits.move', $visit), [
        'date' => '2030-06-12', 'start_time' => '09:00', 'from_user_id' => $helper->id, 'to_user_id' => null,
    ]);
    expect($ids())->toBe([$this->owner->id]);

    // Onto a lane where the person is already assigned: no duplicate.
    $this->put(route('visits.move', $visit), [
        'date' => '2030-06-12', 'start_time' => '09:00', 'from_user_id' => null, 'to_user_id' => $this->owner->id,
    ]);
    expect($ids())->toBe([$this->owner->id]);
});

test('a visit from the unassigned lane gets the person it is dropped on', function () {
    $visit = visitAt('2030-06-12 09:00');

    $this->put(route('visits.move', $visit), [
        'date' => '2030-06-12', 'start_time' => '10:00', 'from_user_id' => null, 'to_user_id' => $this->tech->id,
    ])->assertRedirect();

    expect(inCompany($this->company, fn () => JobVisit::find($visit->id)->assignees()->pluck('users.id')->all()))
        ->toBe([$this->tech->id]);
});

test('started visits and visits of inactive jobs cannot be moved', function () {
    $started = visitAt('2030-06-12 09:00', visit: ['status' => VisitStatus::InProgress]);
    $onHold = visitAt('2030-06-12 11:00', job: ['status' => JobStatus::OnHold]);

    foreach ([$started, $onHold] as $visit) {
        $this->put(route('visits.move', $visit), ['date' => '2030-06-13', 'start_time' => '09:00'])
            ->assertSessionHasErrors('visit');

        expect($visit->fresh()->scheduled_start->utc()->toDateTimeString())->toBe($visit->scheduled_start->utc()->toDateTimeString());
    }
});

test('a visit can only be moved to someone who can be assigned', function () {
    $visit = visitAt('2030-06-12 09:00');
    $outsider = memberOf(Company::factory()->create(), UserRole::Technician);
    $inactive = memberOf($this->company, UserRole::Technician);
    DB::table('company_user')->where('user_id', $inactive->id)->update(['is_active' => false]);

    foreach ([$outsider, $inactive] as $person) {
        $this->put(route('visits.move', $visit), ['date' => '2030-06-12', 'start_time' => '09:00', 'to_user_id' => $person->id])
            ->assertSessionHasErrors('to_user_id');
    }

    $this->put(route('visits.move', $visit), ['date' => '12/06/2030', 'start_time' => '9am'])
        ->assertSessionHasErrors(['date', 'start_time']);
});

test('a person who left the team keeps a lane while they still have visits', function () {
    $former = memberOf($this->company, UserRole::Technician, ['name' => 'Fred Former']);
    visitAt('2030-06-12 09:00', 120, [$former]);
    DB::table('company_user')->where('user_id', $former->id)->update(['is_active' => false]);

    expect(collect(calendarProps()['lanes'])->firstWhere('id', $former->id))
        ->toBe(['id' => $former->id, 'name' => 'Fred Former', 'inactive' => true]);
});

test('technicians have no calendar and cannot move visits', function () {
    $visit = visitAt('2030-06-12 09:00', 120, [test()->tech]);

    $this->actingAs($this->tech);

    $this->get(route('calendar'))->assertForbidden();
    $this->put(route('visits.move', $visit), ['date' => '2030-06-13', 'start_time' => '09:00'])->assertForbidden();
});

test('a brand-limited admin sees and moves only their brands\' visits', function () {
    $otherBrand = Brand::factory()->create(['company_id' => $this->company->id]);
    $admin = memberOf($this->company, UserRole::Admin);
    DB::table('brand_user')->insert([
        'company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'user_id' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $mine = visitAt('2030-06-12 09:00');
    $theirs = visitAt('2030-06-12 11:00', job: ['brand_id' => $otherBrand->id]);

    $this->actingAs($admin);

    expect(collect(calendarProps()['visits'])->pluck('id')->all())->toBe([$mine->id]);
    $this->put(route('visits.move', $theirs), ['date' => '2030-06-13', 'start_time' => '09:00'])->assertForbidden();
});

test('an invalid date falls back to today', function () {
    expect(calendarProps(['date' => '2030-02-31'])['date'])->toBe('2030-06-12')
        ->and(calendarProps(['date' => 'nonsense', 'view' => 'month'])['view'])->toBe('day');
});
