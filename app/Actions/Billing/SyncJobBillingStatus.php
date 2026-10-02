<?php

namespace App\Actions\Billing;

use App\Actions\Jobs\ChangeJobStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Messaging\ReviewRequests;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Moves a finished job between completed → invoiced → paid by its invoices.
 * Jobs still in progress (e.g. waiting for parts with a diagnosis fee paid) keep their status;
 * they are synced again when the work is completed.
 */
class SyncJobBillingStatus
{
    public function __construct(
        private readonly ChangeJobStatus $changeStatus,
        private readonly ReviewRequests $reviews,
    ) {}

    public function handle(ServiceJob $job, ?User $user): void
    {
        if (! in_array($job->status, [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid], true)) {
            return;
        }

        $invoices = $job->invoices()->where('status', '!=', InvoiceStatus::Void->value)->get(['id', 'status', 'balance']);

        // Settled: paid in full, or refunded as settled (nothing left to pay).
        $target = match (true) {
            $invoices->isEmpty() => JobStatus::Completed,
            $invoices->every(fn ($invoice) => in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyRefunded], true)
                || ($invoice->status === InvoiceStatus::Refunded && $invoice->balance <= 0)) => JobStatus::Paid,
            default => JobStatus::Invoiced,
        };

        $wasPaid = $job->status === JobStatus::Paid;
        $this->changeStatus->handle($job, $target, $user);

        // Paid in full: the Google review request is scheduled (if asked for on the job).
        if ($target === JobStatus::Paid && ! $wasPaid) {
            $this->reviews->schedule($job);
        }
    }
}
