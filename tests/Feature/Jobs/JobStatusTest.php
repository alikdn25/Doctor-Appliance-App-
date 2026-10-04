<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $this->visit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();
});

function statusLog(ServiceJob $job): array
{
    return JobStatusChange::withoutCompanyScope()
        ->where('service_job_id', $job->id)
        ->orderBy('id')
        ->get()
        ->map(fn (JobStatusChange $c) => [$c->from_status?->value, $c->to_status->value])
        ->all();
}

test('a technician moves a visit through on my way, start and complete', function () {
    $this->actingAs($this->tech);
    $this->travelTo('2030-06-12 16:00:00');

    $this->post(route('visits.on-my-way', $this->visit))->assertRedirect();
    expect($this->job->fresh()->status)->toBe(JobStatus::OnTheWay)
        ->and($this->visit->fresh())->status->toBe(VisitStatus::OnTheWay)
        ->on_the_way_at->toDateTimeString()->toBe('2030-06-12 16:00:00');

    $this->travelTo('2030-06-12 16:25:00');
    $this->post(route('visits.start', $this->visit))->assertRedirect();
    expect($this->job->fresh()->status)->toBe(JobStatus::InProgress);

    $this->travelTo('2030-06-12 17:40:00');
    $this->post(route('visits.finish', $this->visit), ['outcome' => 'completed', 'note' => 'Replaced drain pump.'])->assertRedirect();

    $job = $this->job->fresh();
    $visit = $this->visit->fresh();

    expect($job->status)->toBe(JobStatus::Completed)
        ->and($job->completed_at->toDateTimeString())->toBe('2030-06-12 17:40:00')
        ->and($visit->status)->toBe(VisitStatus::Completed)
        ->and($visit->started_at->toDateTimeString())->toBe('2030-06-12 16:25:00')
        ->and($visit->finished_at->toDateTimeString())->toBe('2030-06-12 17:40:00')
        ->and($visit->minutesOnJob())->toBe(75);

    $changes = JobStatusChange::withoutCompanyScope()->where('service_job_id', $this->job->id)->orderBy('id')->get();

    expect(statusLog($this->job))->toBe([
        ['scheduled', 'on_the_way'],
        ['on_the_way', 'in_progress'],
        ['in_progress', 'completed'],
    ])
        ->and($changes->pluck('user_id')->unique()->all())->toBe([$this->tech->id])
        ->and($changes->pluck('job_visit_id')->unique()->all())->toBe([$this->visit->id])
        ->and($changes->last()->note)->toBe('Replaced drain pump.')
        ->and($changes->last()->created_at->toDateTimeString())->toBe('2030-06-12 17:40:00');
});

test('a technician can start without pressing on my way', function () {
    $this->actingAs($this->tech)->post(route('visits.start', $this->visit))->assertRedirect();

    expect($this->job->fresh()->status)->toBe(JobStatus::InProgress)
        ->and($this->visit->fresh()->on_the_way_at)->toBeNull();
});

test('waiting for parts, then a second visit completes the job', function () {
    $this->actingAs($this->tech);
    $this->post(route('visits.start', $this->visit));
    $this->post(route('visits.finish', $this->visit), ['outcome' => 'waiting_for_parts', 'note' => 'Needs pump']);

    expect($this->job->fresh()->status)->toBe(JobStatus::WaitingForParts);

    $this->actingAs($this->owner)
        ->post(route('visits.store', $this->job), [
            'date' => '2030-07-01', 'start_time' => '13:00', 'end_time' => '15:00',
            'assignee_ids' => [$this->tech->id],
        ])
        ->assertRedirect(route('jobs.show', $this->job));

    expect($this->job->fresh()->status)->toBe(JobStatus::Scheduled);

    $second = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->whereKeyNot($this->visit->id)->sole();

    $this->actingAs($this->tech);
    $this->post(route('visits.start', $second));
    $this->post(route('visits.finish', $second), ['outcome' => 'completed']);

    expect($this->job->fresh()->status)->toBe(JobStatus::Completed)
        ->and(statusLog($this->job))->toBe([
            ['scheduled', 'in_progress'],
            ['in_progress', 'waiting_for_parts'],
            ['waiting_for_parts', 'scheduled'],
            ['scheduled', 'in_progress'],
            ['in_progress', 'completed'],
        ]);
});

test('status buttons only work in order', function () {
    $this->actingAs($this->tech);

    $this->post(route('visits.finish', $this->visit), ['outcome' => 'completed'])->assertSessionHasErrors('status');

    $this->post(route('visits.start', $this->visit));
    $this->post(route('visits.start', $this->visit))->assertSessionHasErrors('status');
    $this->post(route('visits.on-my-way', $this->visit))->assertSessionHasErrors('status');
    $this->post(route('visits.finish', $this->visit), ['outcome' => 'paid'])->assertSessionHasErrors('outcome');

    $this->post(route('visits.finish', $this->visit), ['outcome' => 'completed']);
    $this->post(route('visits.finish', $this->visit), ['outcome' => 'completed'])->assertSessionHasErrors('status');

    expect(statusLog($this->job))->toHaveCount(2);
});

test('nobody can work on a job that is on hold or cancelled', function (string $status) {
    $this->actingAs($this->owner)->put(route('jobs.status', $this->job), ['status' => $status, 'reason' => 'Customer changed their mind'])->assertRedirect();

    $this->actingAs($this->tech)
        ->post(route('visits.on-my-way', $this->visit))
        ->assertSessionHasErrors('status');

    expect($this->job->fresh()->status->value)->toBe($status);
})->with(['on_hold', 'cancelled']);

test('the office changes the status by hand with a note', function () {
    $this->actingAs($this->owner)
        ->put(route('jobs.status', $this->job), ['status' => 'on_hold', 'note' => 'Customer travelling'])
        ->assertRedirect();

    $change = JobStatusChange::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();

    expect($this->job->fresh()->status)->toBe(JobStatus::OnHold)
        ->and($change)
        ->from_status->toBe(JobStatus::Scheduled)
        ->to_status->toBe(JobStatus::OnHold)
        ->user_id->toBe($this->owner->id)
        ->note->toBe('Customer travelling');
});

test('cancelling a job cancels its scheduled visits and blocks new ones', function () {
    $this->actingAs($this->owner)->put(route('jobs.status', $this->job), ['status' => 'cancelled', 'reason' => 'Nobody home / door not opened']);

    expect($this->job->fresh())->status->toBe(JobStatus::Cancelled)->cancelled_at->not->toBeNull()
        ->and($this->visit->fresh()->status)->toBe(VisitStatus::Cancelled);

    $this->post(route('visits.store', $this->job), ['date' => '2030-07-01', 'start_time' => '13:00', 'end_time' => '15:00'])
        ->assertSessionHasErrors('date');

    expect(JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->count())->toBe(1);
});

test('invoiced and paid cannot be set by hand, and locked jobs cannot change', function () {
    $this->actingAs($this->owner);

    $this->put(route('jobs.status', $this->job), ['status' => 'invoiced'])->assertSessionHasErrors('status');
    $this->put(route('jobs.status', $this->job), ['status' => 'paid'])->assertSessionHasErrors('status');

    inCompany($this->company, fn () => $this->job->forceFill(['status' => JobStatus::Paid])->save());

    $this->put(route('jobs.status', $this->job), ['status' => 'new'])->assertSessionHasErrors('status');
    expect($this->job->fresh()->status)->toBe(JobStatus::Paid);
});

test('reopening a completed job clears the completion time', function () {
    inCompany($this->company, fn () => $this->job->forceFill(['status' => JobStatus::Completed, 'completed_at' => now()])->save());

    $this->actingAs($this->owner)->put(route('jobs.status', $this->job), ['status' => 'in_progress']);

    expect($this->job->fresh())->status->toBe(JobStatus::InProgress)->completed_at->toBeNull();
});

test('deleting the only scheduled visit puts the job back to new', function () {
    $this->actingAs($this->owner)->delete(route('visits.destroy', $this->visit))->assertRedirect();

    expect(JobVisit::withoutCompanyScope()->find($this->visit->id))->toBeNull()
        ->and($this->job->fresh()->status)->toBe(JobStatus::New)
        ->and(statusLog($this->job))->toBe([['scheduled', 'new']]);
});

test('a visit that has started cannot be deleted', function () {
    $this->actingAs($this->tech)->post(route('visits.start', $this->visit));

    $this->actingAs($this->owner)->delete(route('visits.destroy', $this->visit))->assertSessionHasErrors('visit');

    expect(JobVisit::withoutCompanyScope()->find($this->visit->id))->not->toBeNull();
});

test('the office reschedules a visit and changes who goes', function () {
    $other = memberOf($this->company, UserRole::Technician);

    $this->actingAs($this->owner)
        ->put(route('visits.update', $this->visit), [
            'date' => '2030-08-01', 'start_time' => '08:00', 'end_time' => '10:00',
            'estimated_duration_minutes' => 45, 'assignee_ids' => [$other->id],
        ])
        ->assertRedirect(route('jobs.show', $this->job));

    $visit = inCompany($this->company, fn () => JobVisit::with('assignees')->find($this->visit->id));

    expect($visit->assignees->pluck('id')->all())->toBe([$other->id])
        ->and($visit->estimated_duration_minutes)->toBe(45)
        ->and($visit->scheduled_start->setTimezone($this->company->timezone)->format('Y-m-d H:i'))->toBe('2030-08-01 08:00');
});

test('the office can press the status buttons for a technician', function () {
    $this->actingAs($this->owner)->post(route('visits.start', $this->visit))->assertRedirect();

    expect($this->job->fresh()->status)->toBe(JobStatus::InProgress);
});

test('the job page offers the status buttons to whoever is assigned', function () {
    $this->actingAs($this->tech)
        ->get(route('jobs.show', $this->job))
        ->assertInertia(fn ($page) => $page
            ->where('myVisitId', $this->visit->id)
            ->where('job.visits.0.is_mine', true)
            // The technician on the job marks only what it is waiting for.
            ->where('statusOptions', fn ($options) => collect($options)->pluck('value')->all() === ['parts_to_order', 'estimate_to_send', 'waiting_for_parts', 'waiting_for_customer'])
            ->where('can.update', false)
            ->where('can.work', true));
});

test('the technician on the job sets a waiting reason, and sending the estimate moves it to waiting for the customer', function () {
    $this->actingAs($this->tech)->put(route('jobs.status', $this->job), ['status' => 'estimate_to_send'])->assertSessionHasNoErrors();
    expect($this->job->fresh()->status)->toBe(JobStatus::EstimateToSend);
    $this->put(route('jobs.status', $this->job), ['status' => 'cancelled', 'reason' => 'x'])->assertForbidden();

    $this->post(route('estimates.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $estimate = inCompany($this->company, fn () => Estimate::latest('id')->first());
    $this->post(route('estimates.send', $estimate), ['email' => 'jane@example.com', 'message' => 'Your estimate'])->assertSessionHasNoErrors();

    expect($this->job->fresh()->status)->toBe(JobStatus::WaitingForCustomer);
});
