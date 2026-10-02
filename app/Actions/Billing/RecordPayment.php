<?php

namespace App\Actions\Billing;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment towards an invoice: by hand (cash, check, bank transfer, own terminal, other)
 * or from a payment provider (webhook). Must run in a tenant context.
 */
class RecordPayment
{
    public function __construct(private readonly SyncInvoice $syncInvoice) {}

    /**
     * A payment entered by a person. Partial payments are fine; more than the balance is not.
     */
    public function manual(
        Invoice $invoice,
        int $amount,
        PaymentMethod $method,
        ?string $reference,
        ?string $note,
        CarbonInterface $receivedAt,
        User $user,
    ): Payment {
        if (! in_array($method, PaymentMethod::manual(), true)) {
            throw ValidationException::withMessages(['method' => __('payments.errors.invalid_method')]);
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $note, $receivedAt, $user) {
            $invoice = $this->lock($invoice);

            if ($amount > $invoice->balance) {
                throw ValidationException::withMessages(['amount' => __('payments.errors.more_than_balance')]);
            }

            return $this->create($invoice, [
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'received_at' => $receivedAt,
            ], $user);
        });
    }

    /**
     * A payment reported by a payment provider. Idempotent on the provider's payment ID,
     * so a repeated webhook does not record it twice.
     */
    public function fromProvider(
        Invoice $invoice,
        string $provider,
        string $providerPaymentId,
        int $amount,
        CarbonInterface $receivedAt,
        ?string $reference = null,
        ?string $currency = null,
        int $tip = 0,
    ): Payment {
        return DB::transaction(function () use ($invoice, $provider, $providerPaymentId, $amount, $receivedAt, $reference, $currency, $tip) {
            $invoice = $this->lock($invoice);

            if ($currency !== null && strtoupper($currency) !== $invoice->currency) {
                throw ValidationException::withMessages(['amount' => __('payments.errors.currency_mismatch', [
                    'currency' => strtoupper($currency), 'expected' => $invoice->currency,
                ])]);
            }

            $existing = Payment::query()
                ->where('provider', $provider)
                ->where('provider_payment_id', $providerPaymentId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->create($invoice, [
                'amount' => $amount,
                // A tip is the customer's extra to the company: kept on the payment, not applied to the invoice.
                'tip_amount' => max(0, $tip),
                'method' => PaymentMethod::Online,
                'reference' => $reference,
                'received_at' => $receivedAt,
                'provider' => $provider,
                'provider_payment_id' => $providerPaymentId,
            ], null);
        });
    }

    /**
     * A refund reported by the provider for one of its payments. Stored as a payment row with a negative amount,
     * so the invoice's paid amount, balance and status follow. Idempotent on the provider's refund ID.
     * The refund is applied to the invoice up to what is left of the payment; anything beyond it refunds the tip.
     */
    public function refundFromProvider(Payment $original, string $providerRefundId, int $amount, CarbonInterface $refundedAt): Payment
    {
        return DB::transaction(function () use ($original, $providerRefundId, $amount, $refundedAt) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($original->invoice_id);

            $existing = Payment::query()
                ->where('provider', $original->provider)
                ->where('provider_payment_id', $providerRefundId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $earlier = Payment::query()->valid()->where('refunded_payment_id', $original->id)->get();
            $left = $original->amount + (int) $earlier->sum('amount');
            $tipLeft = $original->tip_amount + (int) $earlier->sum('tip_amount');
            $applied = min(max(0, $amount), max(0, $left));
            $tip = min(max(0, $amount - $applied), max(0, $tipLeft));

            $refund = new Payment([
                'amount' => -$applied,
                'tip_amount' => -$tip,
                'method' => PaymentMethod::Online,
                'reference' => __('payments.refund_of', ['reference' => $original->reference ?? $original->provider_payment_id]),
                'received_at' => $refundedAt,
                'provider' => $original->provider,
                'provider_payment_id' => $providerRefundId,
            ]);
            $refund->invoice_id = $invoice->id;
            $refund->currency = $invoice->currency;
            $refund->refunded_payment_id = $original->id;
            $refund->save();

            $this->syncInvoice->handle($invoice, null);

            return $refund;
        });
    }

    private function lock(Invoice $invoice): Invoice
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['amount' => __('invoices.errors.void')]);
        }

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(Invoice $invoice, array $attributes, ?User $user): Payment
    {
        if ($attributes['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => __('payments.errors.amount_required')]);
        }

        $payment = new Payment($attributes);
        $payment->invoice_id = $invoice->id;
        $payment->currency = $invoice->currency;
        $payment->user_id = $user?->id;
        $payment->save();

        $this->syncInvoice->handle($invoice, $user);

        return $payment;
    }
}
