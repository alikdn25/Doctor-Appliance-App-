<?php

namespace App\Actions\Billing;

use App\Actions\Jobs\ChangeJobStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Moves a job by its invoices (owner's task of November 25):
 * - an invoice sent with money owed → "awaiting_payment" (off Today and the schedule; its open visit is finished);
 * - every invoice settled (paid in full, or refunded as settled) → "paid", by itself, whatever paid it;
 * - a finished job without a sent invoice: completed → invoiced → paid as before.
 * Sending an estimate changes nothing. Jobs still in progress without a sent invoice (e.g. a diagnosis fee paid on
 * site) and jobs in the parts flow keep their status; they are synced again when the work is completed.
 */
class SyncJobBillingStatus
{
    public function __construct(private readonly ChangeJobStatus $changeStatus) {}

    public function handle(ServiceJob $job, ?User $user): void
    {
        if (in_array($job->status, [JobStatus::Cancelled, JobStatus::Draft], true)) {
            return;
        }

        $invoices = $job->invoices()->where('status', '!=', InvoiceStatus::Void->value)->get(['id', 'status', 'balance', 'sent_at']);
        $sentOwed = $invoices->contains(fn (Invoice $invoice) => $invoice->sent_at !== null && ! self::settled($invoice));

        if ($job->status === JobStatus::AwaitingPayment || ($sentOwed && ! $job->status->waitsForParts())) {
            $target = match (true) {
                $invoices->isEmpty() => JobStatus::Completed,
                $this->allSettled($invoices) => JobStatus::Paid,
                $sentOwed => JobStatus::AwaitingPayment,
                default => JobStatus::Invoiced,
            };

            if ($target === JobStatus::AwaitingPayment && $job->status !== JobStatus::AwaitingPayment) {
                $this->finishOpenVisits($job);
            }

            $this->changeStatus->handle($job, $target, $user);

            return;
        }

        if (! in_array($job->status, [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid], true)) {
            return;
        }

        $target = match (true) {
            $invoices->isEmpty() => JobStatus::Completed,
            $this->allSettled($invoices) => JobStatus::Paid,
            default => JobStatus::Invoiced,
        };

        $this->changeStatus->handle($job, $target, $user);
    }

    /**
     * Nothing is left to pay on it: paid in full, or refunded as settled.
     */
    public static function settled(Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyRefunded], true)
            || ($invoice->status === InvoiceStatus::Refunded && $invoice->balance <= 0);
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function allSettled(Collection $invoices): bool
    {
        return $invoices->every(fn (Invoice $invoice) => self::settled($invoice));
    }

    /**
     * The invoice went to the customer: a visit still on the way or under way is over (started now when it never was).
     */
    private function finishOpenVisits(ServiceJob $job): void
    {
        $job->visits()->whereIn('status', [VisitStatus::OnTheWay->value, VisitStatus::InProgress->value])->get()
            ->each(function ($visit) {
                $visit->status = VisitStatus::Completed;
                $visit->started_at ??= now();
                $visit->finished_at = now();
                $visit->save();
            });

        $job->completed_at ??= now();
    }
}
