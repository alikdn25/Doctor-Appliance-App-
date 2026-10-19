<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's fixes of October 19: the technician of a job in one tap, the load of each person on the job's day, and
 * "Transfer" of ticked jobs to another person.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    $this->alex = memberOf($this->company, UserRole::Technician, ['name' => 'Alex Tech']);
    $this->sam = memberOf($this->company, UserRole::Technician, ['name' => 'Sam Tech']);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->day = CarbonImmutable::parse('2030-03-05 00:00', 'America/Vancouver');
    $this->actingAs($this->owner);
});

function assignmentVisitAt(ServiceJob $job, CarbonImmutable $start, array $users = []): JobVisit
{
    return JobVisit::factory()->for($job, 'job')->assignedTo($users)
        ->create(['scheduled_start' => $start->utc(), 'scheduled_end' => $start->addHours(2)->utc()]);
}

function assignedJob(object $test, array $users = [], int $hour = 11): array
{
    $job = ServiceJob::factory()->for($test->property)->create(['status' => JobStatus::Scheduled]);

    return [$job, assignmentVisitAt($job, $test->day->setTime($hour, 0), $users)];
}

test('one tap makes a person the technician of the job and its open visit', function () {
    [$job, $visit] = assignedJob($this);

    $this->put(route('jobs.technician', $job), ['user_id' => $this->alex->id])->assertSessionHasNoErrors()->assertRedirect();

    expect(inCompany($this->company, fn () => $visit->assignees()->pluck('users.id')->all()))->toBe([$this->alex->id]);

    // Changing it replaces the person; "Not assigned" clears it.
    $this->put(route('jobs.technician', $job), ['user_id' => $this->sam->id]);
    expect(inCompany($this->company, fn () => $visit->assignees()->pluck('users.id')->all()))->toBe([$this->sam->id]);
    $this->put(route('jobs.technician', $job), ['user_id' => null]);
    expect(inCompany($this->company, fn () => $visit->assignees()->count()))->toBe(0);
});

test('a job without a booked time remembers its technician', function () {
    $job = ServiceJob::factory()->for($this->property)->create();

    $this->put(route('jobs.technician', $job), ['user_id' => $this->alex->id])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => $job->fresh());
    expect($job->assignment_is_explicit)->toBeTrue()
        ->and(inCompany($this->company, fn () => $job->assignees()->pluck('users.id')->all()))->toBe([$this->alex->id]);
    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('job.assignees.0.name', 'Alex Tech')
        ->where('can.assign', true));
});

test('technicians cannot reassign jobs and nobody can assign another company\'s person or job', function () {
    [$job] = assignedJob($this, [$this->alex]);
    $foreign = memberOf(Company::factory()->create(), UserRole::Technician);
    $other = Company::factory()->create();
    $otherJob = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))->create();

    $this->actingAs($this->alex)->put(route('jobs.technician', $job), ['user_id' => $this->alex->id])->assertForbidden();
    $this->actingAs($this->owner)->put(route('jobs.technician', $job), ['user_id' => $foreign->id])->assertSessionHasErrors('user_id');

    $this->put(route('jobs.technician', $otherJob), ['user_id' => $this->alex->id])->assertNotFound();
});

test('the choice shows each person\'s jobs on the day with their times', function () {
    assignedJob($this, [$this->alex], 11);
    assignedJob($this, [$this->alex], 14);
    assignedJob($this, [$this->sam], 9);

    $technicians = collect($this->getJson(route('technicians.load', ['date' => '2030-03-05']))->assertOk()->json('technicians'))
        ->keyBy('name');

    expect($technicians['Alex Tech']['visits'])->toHaveCount(2)
        ->and($technicians['Alex Tech']['visits'][0]['start'])->toBe('11:00')
        ->and($technicians['Alex Tech']['visits'][0]['end'])->toBe('13:00')
        ->and($technicians['Sam Tech']['visits'])->toHaveCount(1)
        ->and($technicians[$this->owner->name]['visits'])->toBe([]);

    $this->actingAs($this->alex)->getJson(route('technicians.load', ['date' => '2030-03-05']))->assertForbidden();
});

test('Transfer moves only the ticked jobs, and what is left can go to someone else', function () {
    [, $first] = assignedJob($this, [$this->alex], 9);
    [, $second] = assignedJob($this, [$this->alex], 11);
    [, $third] = assignedJob($this, [$this->alex], 14);

    $this->post(route('visits.transfer'), [
        'visit_ids' => [$first->id, $second->id], 'from_user_id' => $this->alex->id, 'to_user_id' => $this->sam->id,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $people = fn (JobVisit $v) => inCompany($this->company, fn () => $v->assignees()->pluck('users.id')->all());
    expect($people($first))->toBe([$this->sam->id])
        ->and($people($second))->toBe([$this->sam->id])
        ->and($people($third))->toBe([$this->alex->id]);

    $this->post(route('visits.transfer'), [
        'visit_ids' => [$third->id], 'from_user_id' => $this->alex->id, 'to_user_id' => $this->owner->id,
    ])->assertSessionHasNoErrors();
    expect($people($third))->toBe([$this->owner->id]);

    // The Team card lists a person's open jobs from today on.
    Carbon\Carbon::setTestNow('2030-03-01 12:00');
    $sam = inCompany($this->company, fn () => Membership::query()->where('user_id', $this->sam->id)->sole());
    expect($this->getJson(route('technicians.open-visits', $sam))->json('visits'))->toHaveCount(2);
    Carbon\Carbon::setTestNow();
});

test('Transfer is for the office and never reaches another company\'s visits', function () {
    [, $visit] = assignedJob($this, [$this->alex]);
    $other = Company::factory()->create();
    $foreignJob = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))->create();
    $foreignVisit = JobVisit::factory()->for($foreignJob, 'job')->create();

    $this->actingAs($this->alex)->post(route('visits.transfer'), [
        'visit_ids' => [$visit->id], 'to_user_id' => $this->alex->id,
    ])->assertForbidden();

    $this->actingAs($this->owner)->post(route('visits.transfer'), [
        'visit_ids' => [$visit->id, $foreignVisit->id], 'to_user_id' => $this->sam->id,
    ])->assertNotFound();
    expect(inCompany($this->company, fn () => $visit->assignees()->pluck('users.id')->all()))->toBe([$this->alex->id]);
});

test('drag and drop onto another person still works', function () {
    [, $visit] = assignedJob($this, [$this->alex]);

    $this->put(route('visits.move', $visit), [
        'date' => '2030-03-05', 'start_time' => '15:00', 'from_user_id' => $this->alex->id, 'to_user_id' => $this->sam->id,
    ])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => $visit->assignees()->pluck('users.id')->all()))->toBe([$this->sam->id]);
});
