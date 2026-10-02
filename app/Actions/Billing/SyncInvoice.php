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
        $paid = (int) $invoice->payments()->valid()->sum('amount');

        $invoice->amount_paid = $paid;

        if ($invoice->isVoid()) {
            $invoice->balance = 0;
            $invoice->paid_at = null;
        } else {
            $invoice->balance = $invoice->total - $paid;
            $invoice->status = match (true) {
                $invoice->balance <= 0 => InvoiceStatus::Paid,
                $paid > 0 => InvoiceStatus::PartiallyPaid,
                default => InvoiceStatus::Unpaid,
            };
            $invoice->paid_at = $invoice->status === InvoiceStatus::Paid ? ($invoice->paid_at ?? now()) : null;
        }

        $invoice->save();

        $this->syncJob->handle($invoice->job()->firstOrFail(), $user);
    }
}
