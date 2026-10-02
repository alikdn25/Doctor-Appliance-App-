<?php

namespace App\Actions\Jobs;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonImmutable;
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
     * Drag and drop on the calendar: a new start (the window keeps its length) and, when dropped
     * on another person's lane, that person instead of the one it was dragged from.
     * $from / $to null means the "unassigned" lane.
     */
    public function move(JobVisit $visit, CarbonImmutable $start, ?int $from, ?int $to, ?User $user): JobVisit
    {
        if ($visit->status !== VisitStatus::Scheduled || ! $visit->job->status->allowsVisitWork()) {
            throw ValidationException::withMessages(['visit' => __('calendar.errors.not_movable')]);
        }

        $length = (int) $visit->scheduled_start->diffInMinutes($visit->scheduled_end);
        $ids = $visit->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        if ($from !== $to) {
            $ids = array_values(array_filter($ids, fn (int $id) => $id !== $from));

            if ($to !== null && ! in_array($to, $ids, true)) {
                $ids[] = $to;
            }
        }

        return $this->handle($visit->job, $visit, [
            'scheduled_start' => $start->utc(),
            'scheduled_end' => $start->addMinutes($length)->utc(),
            'estimated_duration_minutes' => $visit->estimated_duration_minutes,
        ], $ids, $user);
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
