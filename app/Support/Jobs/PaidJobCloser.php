<?php

namespace App\Support\Jobs;

use App\Actions\Billing\SyncJobBillingStatus;
use App\Actions\Jobs\CloseJob;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A job paid in full is over (owner's task of November 26):
 * - a payment the customer made online (payment link, invoice page) closes the job by itself, even while a visit is
 *   on the way or in progress; that visit is finished at the time of the payment;
 * - jobs:close-paid closes, once, the open jobs whose invoices are all paid and that have no visit booked after the
 *   last payment.
 * A reopened job is not closed by an invoice made before it was reopened, and a visit booked after the payment keeps
 * the job open. Jobs in the parts flow, waiting for the customer or on hold keep their status. Nothing is sent to the
 * customer. Must run in a tenant context.
 */
class PaidJobCloser
{
    /** Statuses a payment may close. */
    public const OPEN = [JobStatus::New, JobStatus::Scheduled, JobStatus::OnTheWay, JobStatus::InProgress,
        JobStatus::Completed, JobStatus::Invoiced, JobStatus::AwaitingPayment, JobStatus::Paid];

    public function __construct(private readonly CloseJob $close, private readonly AuditLogger $audit) {}

    /**
     * After an online payment was recorded.
     */
    public function afterOnlinePayment(Payment $payment): void
    {
        $invoice = $payment->invoice()->first();
        $job = $invoice?->job()->first();

        if ($invoice === null || $job === null) {
            return;
        }

        // Reopened: only a new invoice (made after reopening) closes it.
        if ($job->reopened_at !== null && ($invoice->created_at === null || ! $invoice->created_at->gt($job->reopened_at))) {
            return;
        }

        if ($this->why($job) !== null) {
            return;
        }

        $this->close($job, $payment->received_at ?? now(), 'job.closed_by_online_payment');
    }

    /**
     * Why the job stays open; null when it is paid in full and nothing was booked after the last payment.
     */
    public function why(ServiceJob $job): ?string
    {
        if ($job->outcome !== null || $job->closed_at !== null || $job->trashed()) {
            return 'closed';
        }

        if (! in_array($job->status, self::OPEN, true)) {
            return 'status '.$job->status->value;
        }

        $invoices = $job->invoices()->whereNotIn('status', [InvoiceStatus::Void->value, InvoiceStatus::Draft->value])->get();
        if (! $invoices->contains(fn (Invoice $invoice) => $invoice->total > 0)
            || ! $invoices->every(fn (Invoice $invoice) => SyncJobBillingStatus::settled($invoice))) {
            return 'not paid';
        }

        $last = $this->lastPayment($job);
        if ($last === null) {
            return 'not paid';
        }

        // Reopened after the last payment: a new round of work.
        if ($job->reopened_at !== null && $job->reopened_at->gt($last)) {
            return 'reopened after the payment';
        }

        if ($job->visits()->where('created_at', '>', $last)->where('status', '!=', VisitStatus::Cancelled->value)->exists()) {
            return 'visit booked after the payment';
        }

        return null;
    }

    /**
     * When the last payment that counts was recorded.
     */
    public function lastPayment(ServiceJob $job): ?CarbonInterface
    {
        $payment = Payment::query()->valid()->where('amount', '>', 0)
            ->whereIn('invoice_id', $job->invoices()->select('id'))
            ->orderByDesc('created_at')->first(['created_at', 'received_at']);

        return $payment?->created_at;
    }

    /**
     * Finishes a visit still on the way or in progress at $at, then closes the job as repaired (Paid by its
     * invoices). Logged in the change log.
     */
    public function close(ServiceJob $job, CarbonInterface $at, string $log = 'job.closed_paid'): void
    {
        DB::transaction(function () use ($job, $at, $log) {
            $from = $job->status;

            $job->visits()->whereIn('status', [VisitStatus::OnTheWay->value, VisitStatus::InProgress->value])->get()
                ->each(function ($visit) use ($at) {
                    $visit->status = VisitStatus::Completed;
                    $visit->started_at ??= $at;
                    $visit->finished_at = $visit->started_at->gt($at) ? $visit->started_at : $at;
                    $visit->save();
                });

            $this->close->close($job, JobOutcome::Repaired, null, null, null);
            $job->refresh();

            $this->audit->record($log, $job, ['number' => $job->number, 'from' => $from->value, 'to' => $job->status->value]);
        });
    }
}
