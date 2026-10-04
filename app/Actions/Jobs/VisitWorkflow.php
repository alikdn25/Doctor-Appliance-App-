<?php

namespace App\Actions\Jobs;

use App\Actions\Billing\SyncJobBillingStatus;
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
        });
    }

    /**
     * @param  JobStatus  $outcome  Completed, PartsToOrder, EstimateToSend or WaitingForParts.
     */
    public function finish(JobVisit $visit, User $user, JobStatus $outcome, ?string $note = null): void
    {
        if (! in_array($outcome, [JobStatus::Completed, JobStatus::PartsToOrder, JobStatus::EstimateToSend, JobStatus::WaitingForParts], true)) {
            throw ValidationException::withMessages(['outcome' => __('jobs.errors.invalid_transition')]);
        }

        $this->step($visit, [VisitStatus::InProgress], function (JobVisit $visit) use ($user, $outcome, $note) {
            $visit->status = VisitStatus::Completed;
            $visit->finished_at = now();
            $visit->save();

            $this->changeStatus->handle($visit->job, $outcome, $user, $visit, $note);

            // Invoiced or paid already on site: the finished job moves on to invoiced/paid.
            $this->syncBilling->handle($visit->job, $user);
        });
    }

    /**
     * @param  list<VisitStatus>  $from
     * @param  \Closure(JobVisit): void  $apply
     */
    private function step(JobVisit $visit, array $from, \Closure $apply): void
    {
        DB::transaction(function () use ($visit, $from, $apply) {
            // Re-read under a lock so a double tap cannot apply the same step twice.
            $visit = $visit->newQuery()->lockForUpdate()->findOrFail($visit->id);

            if (! in_array($visit->status, $from, true)) {
                throw ValidationException::withMessages(['status' => __('jobs.errors.invalid_transition')]);
            }

            if (! $visit->job->status->allowsVisitWork()) {
                throw ValidationException::withMessages(['status' => __('jobs.errors.job_not_active')]);
            }

            $apply($visit);
        });
    }
}
