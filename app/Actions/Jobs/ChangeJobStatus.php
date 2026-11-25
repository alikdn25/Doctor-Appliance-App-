<?php

namespace App\Actions\Jobs;

use App\Enums\JobStatus;
use App\Jobs\SuggestPartNumber;
use App\Models\JobPart;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Parts\SupplierTasks;

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
        } elseif (! in_array($to, [JobStatus::Invoiced, JobStatus::AwaitingPayment, JobStatus::Paid], true)) {
            $job->completed_at = null;
        }

        $job->cancelled_at = $to === JobStatus::Cancelled ? now() : null;
        $job->save();

        // A part is needed and none is listed yet: a row for it on the Parts page (the name is filled in there), and
        // the AI suggests a possible part number (marked "check") in the background.
        if ($to === JobStatus::NeedToOrderParts) {
            if (! $job->parts()->where('status', JobPart::TO_ORDER)->exists()) {
                $part = new JobPart(['service_job_id' => $job->id, 'name' => trim((string) $job->part_name) ?: __('parts.unnamed')]);
                $part->created_by = $user?->id;
                $part->save();
                SupplierTasks::sync();
            }
            SuggestPartNumber::dispatch($job->id, (int) $job->company_id)->afterCommit();
        }

        $this->log($job, $from, $to, $user, $visit, $note);
    }

    /**
     * A history entry without a status change (e.g. a visit finished on a job already paid).
     */
    public function note(ServiceJob $job, ?User $user, ?JobVisit $visit, string $note): void
    {
        $this->log($job, $job->status, $job->status, $user, $visit, $note);
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
