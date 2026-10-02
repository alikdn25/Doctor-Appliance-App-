<?php

namespace App\Actions\Billing;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentProviderException;
use App\Payments\PaymentProviders;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives money back on an invoice as settled (warranty callback the customer declined, goodwill …): the customer
 * does not owe it again. Spread over the invoice's payments, newest first. Payments made through a provider are
 * refunded at the provider (Square); manual payments get a refund row (the office hands the money back). The
 * refunded amount is credited on the invoice, which becomes Partially refunded / Refunded. Must run in a tenant
 * context.
 */
class RefundInvoice
{
    public function __construct(
        private readonly RecordPayment $payments,
        private readonly SyncInvoice $syncInvoice,
        private readonly PaymentProviders $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Invoice $invoice, int $amount, string $reason, User $user): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('payments.errors.amount_required')]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('payments.refunds.reason_required')]);
        }

        DB::transaction(function () use ($invoice, $amount, $reason, $user) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->isVoid() || $amount > $invoice->amount_paid) {
                throw ValidationException::withMessages(['amount' => __('payments.refunds.more_than_paid', ['amount' => $invoice->amount_paid])]);
            }

            $left = $amount;
            $originals = $invoice->payments()->valid()->whereNull('refunded_payment_id')->orderByDesc('received_at')->orderByDesc('id')->get();

            foreach ($originals as $payment) {
                if ($left <= 0) {
                    break;
                }

                $refunded = -1 * (int) Payment::query()->valid()->where('refunded_payment_id', $payment->id)->sum('amount');
                $part = min($left, $payment->amount - $refunded);

                if ($part <= 0) {
                    continue;
                }

                $payment->provider !== null
                    ? $this->provider($payment, $part, $reason)
                    : $this->manual($invoice, $payment, $part, $reason, $user);
                $left -= $part;
            }

            $invoice->credited_amount += $amount;
            $invoice->save();
            $this->syncInvoice->handle($invoice, $user);

            $this->audit->record('invoice.refunded', $invoice, ['number' => $invoice->number, 'amount' => $amount, 'reason' => $reason]);
        });
    }

    private function provider(Payment $payment, int $amount, string $reason): void
    {
        $provider = $this->providers->find($payment->provider);

        if ($provider === null) {
            throw ValidationException::withMessages(['amount' => __('payments.links.no_provider')]);
        }

        try {
            $refundId = $provider->refund($payment, $amount, $reason);
        } catch (PaymentProviderException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $refund = $this->payments->refundFromProvider($payment, $refundId, $amount, now());
        $refund->forceFill(['refund_reason' => $reason])->save();
    }

    private function manual(Invoice $invoice, Payment $payment, int $amount, string $reason, User $user): void
    {
        $refund = new Payment([
            'amount' => -$amount,
            'method' => $payment->method === PaymentMethod::Online ? PaymentMethod::Other : $payment->method,
            'reference' => __('payments.refund_of', ['reference' => $payment->reference ?? $payment->method->label()]),
            'received_at' => now(),
        ]);
        $refund->invoice_id = $invoice->id;
        $refund->estimate_id = $payment->estimate_id;
        $refund->currency = $payment->currency;
        $refund->refunded_payment_id = $payment->id;
        $refund->refund_reason = $reason;
        $refund->user_id = $user->id;
        $refund->save();
    }
}
