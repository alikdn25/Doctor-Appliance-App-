<?php

namespace App\Actions\Jobs;

use App\Enums\JobStatus;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Sets a job's status and logs the change with user and time. Must run in a tenant context.
 */
class ChangeJobStatus
{
    public function handle(
        ServiceJob $job,
        JobStatus $to,
        ?User $user,
        ?JobVisit $visit = null,
        ?string $note = null,
    ): void {
        $from = $job->getOriginal('status');
        $from = $from instanceof JobStatus ? $from : JobStatus::tryFrom((string) $from);

        if ($from === $to) {
            return;
        }

        $job->status = $to;

        if ($to === JobStatus::Completed) {
            $job->completed_at ??= now();
        } elseif (! in_array($to, [JobStatus::Invoiced, JobStatus::Paid], true)) {
            $job->completed_at = null;
        }

        $job->cancelled_at = $to === JobStatus::Cancelled ? now() : null;
        $job->save();

        $this->log($job, $from, $to, $user, $visit, $note);
    }

    /**
     * First log entry of a new job.
     */
    public function created(ServiceJob $job, ?User $user): void
    {
        $this->log($job, null, $job->status, $user);
    }

    private function log(
        ServiceJob $job,
        ?JobStatus $from,
        JobStatus $to,
        ?User $user,
        ?JobVisit $visit = null,
        ?string $note = null,
    ): void {
        JobStatusChange::create([
            'service_job_id' => $job->id,
            'job_visit_id' => $visit?->id,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'note' => $note,
        ]);
    }
}
