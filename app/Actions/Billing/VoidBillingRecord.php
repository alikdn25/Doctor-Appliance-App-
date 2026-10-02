<?php

namespace App\Actions\Billing;

use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a payment entered by mistake, or a whole invoice. Nothing is deleted: voided records stay in
 * the history with who voided them, when and why. Both are audited.
 */
class VoidBillingRecord
{
    public function __construct(
        private readonly SyncInvoice $syncInvoice,
        private readonly AuditLogger $audit,
    ) {}

    public function payment(Payment $payment, User $user, ?string $reason): void
    {
        DB::transaction(function () use ($payment, $user, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->isVoid()) {
                return;
            }

            if ($payment->provider !== null) {
                // Online payments are refunded at the provider, which reports back.
                throw ValidationException::withMessages(['payment' => __('payments.errors.provider_payment')]);
            }

            $payment->forceFill(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason])->save();
            $this->syncInvoice->handle($payment->invoice()->firstOrFail(), $user);

            $this->audit->record('payment.voided', $payment, [
                'invoice_id' => $payment->invoice_id,
                'amount' => $payment->amount,
                'method' => $payment->method->value,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * An invoice with payments cannot be voided; void the payments first. A deposit paid on the estimate is not in the
     * way: it moves back to the estimate. An estimate the invoice was made from can be turned into an invoice again.
     */
    public function invoice(Invoice $invoice, User $user, ?string $reason): void
    {
        DB::transaction(function () use ($invoice, $user, $reason) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->isVoid()) {
                return;
            }

            // A deposit paid on the estimate goes back to the estimate (it counts on the next invoice made from it).
            if ($invoice->estimate_id !== null) {
                Payment::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('estimate_id', $invoice->estimate_id)
                    ->update(['invoice_id' => null]);
                $this->syncInvoice->handle($invoice, $user);
            }

            // Manual payments are voided first; online payments must be refunded in full at the provider.
            if ($invoice->payments()->valid()->whereNull('provider')->exists() || $invoice->amount_paid !== 0) {
                throw ValidationException::withMessages(['invoice' => __('invoices.errors.has_payments')]);
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);
            $this->syncInvoice->handle($invoice, $user);

            $estimate = $invoice->estimate;
            if ($estimate !== null && $estimate->status === EstimateStatus::Invoiced) {
                $estimate->forceFill(['status' => $estimate->approved_at ? EstimateStatus::Approved : EstimateStatus::Draft])->save();
            }

            $this->audit->record('invoice.voided', $invoice, [
                'number' => $invoice->number,
                'total' => $invoice->total,
                'reason' => $reason,
            ]);
        });
    }
}
