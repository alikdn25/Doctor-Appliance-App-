<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;

/**
 * Recalculates what is paid on an invoice and its status, then the job's billing status.
 */
class SyncInvoice
{
    public function __construct(private readonly SyncJobBillingStatus $syncJob) {}

    public function handle(Invoice $invoice, ?User $user): void
    {
        // Refunds are payment rows with a negative amount: amount_paid is net of them.
        $paid = (int) $invoice->payments()->valid()->sum('amount');
        $refunded = $invoice->payments()->valid()->whereNotNull('refunded_payment_id')->exists();

        $invoice->amount_paid = $paid;

        if ($invoice->isVoid()) {
            $invoice->balance = 0;
            $invoice->paid_at = null;
        } else {
            // Money refunded as settled (credited) is not owed again.
            $invoice->balance = $invoice->total - $invoice->credited_amount - $paid;
            // An imported debt still waiting for its amount stays open (owner's task of November 19).
            $needsAmount = $paid <= 0 && $invoice->items()->where('needs_amount', true)->exists();
            // An empty invoice (no lines, or 0) with no payments is a draft, never Paid (owner's task of November 26).
            $empty = $invoice->credited_amount <= 0 && ! $invoice->payments()->valid()->exists()
                && ($invoice->total <= 0 || ! $invoice->items()->exists());
            $invoice->status = match (true) {
                $needsAmount => InvoiceStatus::Unpaid,
                $empty => InvoiceStatus::Draft,
                $invoice->balance <= 0 && $invoice->credited_amount > 0 && $paid <= 0 => InvoiceStatus::Refunded,
                $invoice->balance <= 0 && $invoice->credited_amount > 0 => InvoiceStatus::PartiallyRefunded,
                $invoice->balance <= 0 && ($paid > 0 || ! $refunded) => InvoiceStatus::Paid,
                $paid > 0 => InvoiceStatus::PartiallyPaid,
                $refunded => InvoiceStatus::Refunded,
                default => InvoiceStatus::Unpaid,
            };
            $invoice->paid_at = $invoice->status === InvoiceStatus::Paid ? ($invoice->paid_at ?? now()) : null;
        }

        $invoice->save();

        $this->syncJob->handle($invoice->job()->firstOrFail(), $user);
    }
}
