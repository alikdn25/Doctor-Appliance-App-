<?php

namespace App\Actions\Jobs;

use App\Actions\Billing\SyncJobBillingStatus;
use App\Actions\Mileage\RecordRouteTrip;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Status buttons in the field: On my way → Start → Finish (completed or waiting for parts).
 * Each step stamps the visit (time on job is tracked automatically) and moves the job status.
 */
class VisitWorkflow
{
    public function __construct(
        private readonly ChangeJobStatus $changeStatus,
        private readonly SyncJobBillingStatus $syncBilling,
        private readonly RecordRouteTrip $routeTrip,
    ) {}

    public function onMyWay(JobVisit $visit, User $user): void
    {
        $this->step($visit, [VisitStatus::Scheduled], function (JobVisit $visit) use ($user) {
            $visit->status = VisitStatus::OnTheWay;
            $visit->on_the_way_at = now();
            $visit->save();

            $this->changeStatus->handle($visit->job, JobStatus::OnTheWay, $user, $visit);
        });
    }

    public function start(JobVisit $visit, User $user): void
    {
        $this->step($visit, [VisitStatus::Scheduled, VisitStatus::OnTheWay], function (JobVisit $visit) use ($user) {
            $visit->status = VisitStatus::InProgress;
            $visit->started_at = now();
            $visit->save();

            $this->changeStatus->handle($visit->job, JobStatus::InProgress, $user, $visit);

            // The drive here goes into the mileage log.
            $this->routeTrip->handle($visit, $user);
        });
    }

    /**
     * @param  JobStatus  $outcome  Completed or NeedToOrderParts ("Part needed").
     */
    public function finish(JobVisit $visit, User $user, JobStatus $outcome, ?string $note = null): void
    {
        if (! in_array($outcome, [JobStatus::Completed, JobStatus::NeedToOrderParts], true)) {
            throw ValidationException::withMessages(['outcome' => __('jobs.errors.invalid_transition')]);
        }

        $this->step($visit, [VisitStatus::InProgress], function (JobVisit $visit) use ($user, $outcome, $note) {
            $this->stampFinished($visit);
            $job = $visit->job;

            // Invoiced or paid before the visit was finished: the job keeps its status; the history notes the visit.
            if ($job->status->isLocked()) {
                $this->changeStatus->note($job, $user, $visit, $note ?? __('jobs.history_entry.visit_finished'));

                return;
            }

            $this->changeStatus->handle($job, $outcome, $user, $visit, $note);

            // Invoiced or paid already on site: the finished job moves on to invoiced/paid. The caller closes the job
            // with its outcome (Finish, Close by voice), so it is not closed here as repaired.
            $this->syncBilling->handle($job, $user, autoClose: false);
        }, fn (JobStatus $status) => $status->allowsFinishingVisit());
    }

    /**
     * Closing the job finishes its visit on the way or in progress, as Finish visit does; the job's own status is
     * left to the caller (CloseJob), which records the outcome.
     */
    public function finishForClose(JobVisit $visit, ?User $user): void
    {
        $this->step($visit, [VisitStatus::OnTheWay, VisitStatus::InProgress], function (JobVisit $visit) use ($user) {
            $this->stampFinished($visit);
            $this->changeStatus->note($visit->job, $user, $visit, __('jobs.history_entry.visit_finished'));
        }, fn (JobStatus $status) => $status->allowsFinishingVisit());
    }

    private function stampFinished(JobVisit $visit): void
    {
        $visit->status = VisitStatus::Completed;
        // Closed while still on the way: the visit took no time on site.
        $visit->started_at ??= now();
        $visit->finished_at = now();
        $visit->save();
    }

    /**
     * @param  list<VisitStatus>  $from
     * @param  \Closure(JobVisit): void  $apply
     * @param  (\Closure(JobStatus): bool)|null  $jobAllows  Job statuses the step may run in (default: active jobs).
     */
    private function step(JobVisit $visit, array $from, \Closure $apply, ?\Closure $jobAllows = null): void
    {
        $jobAllows ??= fn (JobStatus $status) => $status->allowsVisitWork();

        DB::transaction(function () use ($visit, $from, $apply, $jobAllows) {
            // Re-read under a lock so a double tap cannot apply the same step twice.
            $visit = $visit->newQuery()->lockForUpdate()->findOrFail($visit->id);

            if (! in_array($visit->status, $from, true)) {
                throw ValidationException::withMessages(['status' => __('jobs.errors.invalid_transition')]);
            }

            if (! $jobAllows($visit->job->status)) {
                throw ValidationException::withMessages(['status' => __('jobs.errors.job_not_active')]);
            }

            $apply($visit);
        });
    }
}
