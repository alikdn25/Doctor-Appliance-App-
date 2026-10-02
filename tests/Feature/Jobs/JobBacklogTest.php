<?php

use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2030-06-12 16:00:00');
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->jobs = ServiceJob::factory()->for($this->property)->state(['brand_id' => $this->brand->id]);
});

test('the queue keeps old, unscheduled and waiting jobs without any date cutoff', function () {
    $overdue = $this->jobs->withVisit($this->tech, [
        'scheduled_start' => '2029-01-01 09:00:00', 'scheduled_end' => '2029-01-01 11:00:00',
    ])->create();
    $unscheduled = $this->jobs->create();
    $parts = $this->jobs->withVisit($this->tech, [
        'status' => VisitStatus::Completed, 'scheduled_start' => '2029-01-02 09:00:00', 'scheduled_end' => '2029-01-02 11:00:00',
    ])->status(JobStatus::WaitingForParts)->create();
    $customer = $this->jobs->status(JobStatus::WaitingForCustomer)->create();
    $hold = $this->jobs->status(JobStatus::OnHold)->create();
    $future = $this->jobs->withVisit($this->tech)->create();

    $this->actingAs($this->owner)->get(route('jobs.backlog'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('jobs/backlog')
            ->where('unfinishedJobs.total', 6)
            ->where('unfinishedJobs.counts', [
                'overdue' => 1, 'needs_schedule' => 1, 'waiting_for_parts' => 1,
                'waiting_for_customer' => 1, 'on_hold' => 1, 'scheduled' => 1,
            ])
            ->where('jobs.total', 6)
            ->where('jobs.data', fn ($rows) => collect($rows)->pluck('id')->all() === [
                $overdue->id, $unscheduled->id, $parts->id, $customer->id, $hold->id, $future->id,
            ])
            ->where('jobs.data.0.backlog_reason', 'overdue')
            ->where('jobs.data.2.backlog_reason', 'waiting_for_parts')
            ->where('jobs.data.5.backlog_reason', 'scheduled'));

    $this->travelTo('2031-06-12 16:00:00');
    $this->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 6)->where('unfinishedJobs.counts.overdue', 2));
});

test('finished, billed, cancelled, deleted and outcome-closed jobs are excluded', function () {
    foreach ([JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled] as $status) {
        $this->jobs->status($status)->create();
    }
    $deleted = $this->jobs->create();
    inCompany($this->company, fn () => $deleted->delete());
    $this->jobs->create(['outcome' => JobOutcome::CustomerDeclined, 'closed_at' => now()]);
    $this->jobs->create(['closed_at' => now()]);
    $open = $this->jobs->create();

    $this->actingAs($this->owner)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1)->where('jobs.total', 1)->where('jobs.data.0.id', $open->id));
});

test('multiple visits count once and a future return removes the overdue flag only', function () {
    $job = $this->jobs->withVisit($this->tech, [
        'scheduled_start' => '2029-01-01 09:00:00', 'scheduled_end' => '2029-01-01 11:00:00',
    ])->create();
    $return = JobVisit::factory()->for($job, 'job')->assignedTo($this->tech)->create();
    JobVisit::factory()->for($job, 'job')->assignedTo($this->tech)->create(['status' => VisitStatus::Cancelled]);

    $this->actingAs($this->owner)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1)->where('unfinishedJobs.counts.overdue', 0)
        ->where('unfinishedJobs.counts.scheduled', 1)->where('jobs.total', 1)
        ->where('jobs.data.0.visit.scheduled_start', $return->scheduled_start->toIso8601String()));
});

test('finished and cancelled visits do not keep a job scheduled', function () {
    $job = $this->jobs->withVisit($this->tech, ['status' => VisitStatus::Completed])->create();
    JobVisit::factory()->for($job, 'job')->create(['status' => VisitStatus::Cancelled]);

    $this->actingAs($this->owner)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.counts.needs_schedule', 1)->where('jobs.data.0.backlog_reason', 'needs_schedule'));
});

test('closing removes work and reopening restores it to the queue', function () {
    $job = $this->jobs->create();
    $this->actingAs($this->owner);
    $this->post(route('jobs.close', $job), ['outcome' => 'customer_declined', 'reason' => 'Repair too expensive'])->assertRedirect();
    $this->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs.total', 0));

    $this->put(route('jobs.status', $job), ['status' => 'new'])->assertRedirect();
    $this->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1)->where('jobs.data.0.id', $job->id));
});

test('cancelling removes work from the counter on every page', function () {
    $job = $this->jobs->create();
    $this->actingAs($this->owner)->put(route('jobs.status', $job), [
        'status' => 'cancelled', 'reason' => 'Nobody home / door not opened',
    ])->assertRedirect();
    $this->get(route('jobs.index'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs.total', 0));
});

test('waiting for customer is logged, stays visible and can be scheduled again', function () {
    $job = $this->jobs->withVisit($this->tech)->create();
    $visit = JobVisit::withoutCompanyScope()->where('service_job_id', $job->id)->sole();
    $this->actingAs($this->owner)->put(route('jobs.status', $job), [
        'status' => 'waiting_for_customer', 'note' => 'Waiting for approval of the repair.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($job->fresh()->status)->toBe(JobStatus::WaitingForCustomer);
    expect(inCompany($this->company, fn () => $job->statusChanges()->latest('id')->first()->note))
        ->toBe('Waiting for approval of the repair.');
    $this->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1)->where('unfinishedJobs.counts.waiting_for_customer', 1));

    $this->actingAs($this->tech)->post(route('visits.start', $visit))->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->post(route('visits.store', $job), [
        'date' => '2030-07-01', 'start_time' => '13:00', 'end_time' => '15:00', 'assignee_ids' => [$this->tech->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($job->fresh()->status)->toBe(JobStatus::Scheduled);
    $this->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1)->where('unfinishedJobs.counts.scheduled', 1));
});

test('technicians see only their assigned unfinished jobs including completed diagnosis visits', function () {
    $mine = $this->jobs->withVisit($this->tech, ['status' => VisitStatus::Completed])->status(JobStatus::WaitingForParts)->create();
    $this->jobs->withVisit(memberOf($this->company, UserRole::Technician))->create();
    $this->jobs->create();
    $this->jobs->withVisit($this->tech)->status(JobStatus::Completed)->create();

    $this->actingAs($this->tech)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1)->where('jobs.total', 1)->where('jobs.data.0.id', $mine->id));
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs.total', 1));
});

test('brand restrictions apply to both the queue and shared counter with assigned jobs still visible', function () {
    $admin = memberOf($this->company, UserRole::Admin);
    DB::table('brand_user')->insert([
        'company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'user_id' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherBrand = Brand::factory()->create(['company_id' => $this->company->id]);
    $allowed = $this->jobs->create();
    $assigned = $this->jobs->withVisit($admin)->create(['brand_id' => $otherBrand->id]);
    $this->jobs->create(['brand_id' => $otherBrand->id]);

    $this->actingAs($admin)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 2)->where('jobs.total', 2)
        ->where('jobs.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$allowed->id, $assigned->id]));
});

test('company switching changes both the queue and shared count without leaking foreign jobs', function () {
    $ours = $this->jobs->create();
    $other = Company::factory()->create();
    Membership::factory()->create(['company_id' => $other->id, 'user_id' => $this->owner->id, 'role' => UserRole::Owner]);
    $foreign = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))->create();
    ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))->create();

    $this->actingAs($this->owner)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1)->where('jobs.total', 1)->where('jobs.data.0.id', $ours->id));
    $this->post(route('companies.switch', $other))->assertRedirect();
    $this->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 2)->where('jobs.total', 2)->where('jobs.data.0.id', $foreign->id));
});

test('counts cover all pages and stay global when the queue is filtered or searched', function () {
    $this->jobs->count(30)->create();
    $parts = $this->jobs->status(JobStatus::WaitingForParts)->create();
    $this->actingAs($this->owner)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 31)->where('jobs.total', 31)->has('jobs.data', 25));
    $this->get(route('jobs.backlog', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 31)->has('jobs.data', 6));
    $this->get(route('jobs.backlog', ['reason' => 'waiting_for_parts', 'search' => '#'.$parts->number]))
        ->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs.total', 31)
            ->where('jobs.total', 1)->where('jobs.data.0.id', $parts->id));
    $this->get(route('jobs.backlog', ['reason' => 'invalid']))->assertInertia(fn (Assert $page) => $page
        ->where('filters.reason', '')->where('jobs.total', 31));
});

test('partial navigation refreshes the shared counter even when only jobs are requested', function () {
    $job = $this->jobs->create();
    $this->actingAs($this->owner)->get(route('jobs.backlog'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs.total', 1));
    inCompany($this->company, fn () => $job->forceFill(['status' => JobStatus::Completed])->save());

    $this->get(route('jobs.backlog'), [
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'jobs/backlog', 'X-Inertia-Partial-Data' => 'jobs',
    ])->assertOk()->assertJsonPath('props.unfinishedJobs.total', 0)->assertJsonPath('props.jobs.total', 0);
});

test('unsupported roles cannot open the queue or obtain its counter', function (UserRole $role) {
    $user = memberOf($this->company, $role);
    $this->jobs->withVisit($user)->create();
    $this->actingAs($user)->get(route('jobs.backlog'))->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs', null));
})->with([UserRole::Collector, UserRole::Subcontractor]);

test('unauthenticated and platform pages do not query a tenant queue', function () {
    $this->jobs->create();
    $this->get(route('jobs.backlog'))->assertRedirect(route('login'));
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('unfinishedJobs', null));
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin)->get(route('admin.companies.index'))->assertInertia(fn (Assert $page) => $page
        ->where('unfinishedJobs', null));
});
