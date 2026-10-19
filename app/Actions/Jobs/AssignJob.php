<?php

namespace App\Actions\Jobs;

use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who goes on a job: one tap on "Technician" (job page, My Jobs), "Transfer" of several jobs to another person
 * (calendar, Team), and "work alone" mode, where every job is the Owner's. Must run in a tenant context.
 */
class AssignJob
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The job's technician becomes this person (null = nobody): its open visits, or the job itself when it has none.
     */
    public function assign(ServiceJob $job, ?int $userId, User $actor): void
    {
        $this->ensureAssignable($userId);
        $userId ??= currentCompany()->soloWorkerId();
        $ids = $userId === null ? [] : [$userId];

        DB::transaction(function () use ($job, $ids, $actor) {
            $visits = $job->visits()->whereIn('status', VisitStatus::openValues())->lockForUpdate()->get();
            foreach ($visits as $visit) {
                $visit->assignees()->sync($ids);
            }

            // Without an open visit the job remembers its person for the next booking; with visits the job follows
            // them, as before.
            if ($visits->isEmpty() || $job->assignment_is_explicit) {
                $job->assignees()->sync(array_fill_keys($ids, ['company_id' => $job->company_id]));
                $job->forceFill(['assignment_is_explicit' => true])->saveQuietly();
            }

            $this->audit->record('job.assigned', $job, ['user_ids' => $ids, 'visits' => $visits->pluck('id')->all(), 'by' => $actor->id]);
        });
    }

    /**
     * Moves the given open visits from one person (null = whoever has them) to another.
     *
     * @param  Collection<int, JobVisit>  $visits
     */
    public function transfer(Collection $visits, ?int $fromUserId, int $toUserId, User $actor): int
    {
        $this->ensureAssignable($toUserId);

        return DB::transaction(function () use ($visits, $fromUserId, $toUserId, $actor) {
            $moved = 0;
            foreach ($visits as $visit) {
                if (! in_array($visit->status->value, VisitStatus::openValues(), true)) {
                    continue;
                }

                $ids = $visit->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
                $ids = $fromUserId === null ? [] : array_values(array_diff($ids, [$fromUserId]));
                $ids[] = $toUserId;
                $visit->assignees()->sync(array_values(array_unique($ids)));
                $this->syncJob($visit->job);
                $moved++;
            }

            $this->audit->record('job.transferred', $actor, ['from_user_id' => $fromUserId, 'to_user_id' => $toUserId, 'visits' => $visits->pluck('id')->all()]);

            return $moved;
        });
    }

    /**
     * "Work alone": open visits nobody has go to the Owner.
     */
    public function giveUnassignedToSoloWorker(User $actor): int
    {
        $owner = currentCompany()->soloWorkerId();
        if ($owner === null) {
            return 0;
        }

        $visits = JobVisit::query()
            ->whereIn('status', VisitStatus::openValues())
            ->whereDoesntHave('assignees')
            ->with('job')
            ->get();

        return $this->transfer($visits, null, $owner, $actor);
    }

    /**
     * A job with an explicit list of people keeps it in step with its open visits.
     */
    private function syncJob(ServiceJob $job): void
    {
        if (! $job->assignment_is_explicit) {
            return;
        }

        $ids = DB::table('job_visit_user')
            ->whereIn('job_visit_id', $job->visits()->whereIn('status', VisitStatus::openValues())->pluck('id'))
            ->pluck('user_id')->unique()->all();
        $job->assignees()->sync(array_fill_keys($ids, ['company_id' => $job->company_id]));
    }

    private function ensureAssignable(?int $userId): void
    {
        if ($userId !== null && ! Membership::query()->assignable()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => __('jobs.errors.invalid_assignee')]);
        }
    }
}
