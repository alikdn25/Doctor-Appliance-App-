<?php

namespace App\Actions\Jobs;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Schedules a visit (arrival window, duration, assigned people) or updates one.
 * Scheduling a visit moves a new / waiting-for-parts / on-hold / completed job to "scheduled".
 */
class SaveVisit
{
    public function __construct(private readonly ChangeJobStatus $changeStatus) {}

    /**
     * @param  array{scheduled_start: mixed, scheduled_end: mixed, estimated_duration_minutes: int|null}  $attributes
     * @param  list<int>  $assigneeIds  Already validated as active members of the company.
     */
    public function handle(ServiceJob $job, ?JobVisit $visit, array $attributes, array $assigneeIds, ?User $user): JobVisit
    {
        if ($visit === null && $job->status === JobStatus::Cancelled) {
            throw ValidationException::withMessages(['date' => __('jobs.errors.job_cancelled')]);
        }

        return DB::transaction(function () use ($job, $visit, $attributes, $assigneeIds, $user) {
            $creating = $visit === null;

            $visit ??= new JobVisit;
            $visit->fill($attributes);
            $visit->job()->associate($job);
            $visit->save();

            $visit->assignees()->sync($assigneeIds);

            if ($creating && $job->status->reschedulable()) {
                $this->changeStatus->handle($job, JobStatus::Scheduled, $user, $visit);
            }

            return $visit;
        });
    }

    /**
     * Removes a visit that has not started yet. A scheduled job without open visits goes back to "new".
     */
    public function delete(JobVisit $visit, ?User $user): void
    {
        if ($visit->status !== VisitStatus::Scheduled) {
            throw ValidationException::withMessages(['visit' => __('jobs.errors.visit_started')]);
        }

        DB::transaction(function () use ($visit, $user) {
            $job = $visit->job;
            $visit->delete();

            if ($job->status === JobStatus::Scheduled
                && ! $job->visits()->whereIn('status', VisitStatus::openValues())->exists()) {
                $this->changeStatus->handle($job, JobStatus::New, $user);
            }
        });
    }
}
