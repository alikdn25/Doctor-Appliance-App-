<?php

namespace App\Actions\Jobs;

use App\Actions\Billing\SyncJobBillingStatus;
use App\Enums\JobStatus;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual status change by the office (e.g. put on hold, cancel, reopen, correct a mistake).
 * Invoiced and paid are set by invoices and payments, never by hand.
 */
class SetJobStatus
{
    public function __construct(
        private readonly ChangeJobStatus $changeStatus,
        private readonly SyncJobBillingStatus $syncBilling,
        private readonly CloseJob $closeJob,
    ) {}

    public function handle(ServiceJob $job, JobStatus $to, User $user, ?string $note = null, ?string $reason = null): void
    {
        // Cancelling needs a reason and is only for jobs nobody started working on.
        if ($to === JobStatus::Cancelled) {
            $this->closeJob->cancel($job, (string) $reason, $note, $user);

            return;
        }

        if ($job->status->isLocked() || ! in_array($to, JobStatus::manual(), true)) {
            throw ValidationException::withMessages(['status' => __('jobs.errors.invalid_transition')]);
        }

        if ($job->status === $to) {
            return;
        }

        DB::transaction(function () use ($job, $to, $user, $note) {
            // Back to work: the job has no outcome any more.
            if ($to !== JobStatus::Completed) {
                CloseJob::reopen($job);
            }

            $this->changeStatus->handle($job, $to, $user, null, $note);
            $this->syncBilling->handle($job, $user);
        });
    }
}
