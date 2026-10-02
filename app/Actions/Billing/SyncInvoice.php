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
            $invoice->status = match (true) {
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
