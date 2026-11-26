<?php

namespace App\Actions\Billing;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentProviderException;
use App\Payments\PaymentProviders;
use App\Services\AuditLogger;
use App\Support\Billing\Money;
use App\Support\Billing\RefundAmounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives money back on an invoice as settled (warranty callback the customer declined, goodwill …): the customer
 * does not owe it again. Spread over the invoice's payments, newest first; any number of refunds per invoice.
 * Payments made through a provider are refunded at the provider (Square, to the same card); if the provider says no,
 * the person may record it instead (record_only: they hand the money back themselves). Cash and e-transfer payments
 * get a refund row only, the same way they were paid unless another way is picked (owner's task of November 26).
 * The refund's taxes are kept on its rows (refund_taxes) so statistics and the tax reserve take exactly them back.
 * The refunded amount is credited on the invoice, which becomes Partially refunded / Refunded. The reason is
 * optional. Must run in a tenant context.
 */
class RefundInvoice
{
    public function __construct(
        private readonly RecordPayment $payments,
        private readonly SyncInvoice $syncInvoice,
        private readonly PaymentProviders $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{taxes?: list<array{name: string, rate: string, amount: int}>|null, method?: PaymentMethod|null, record_only?: bool}  $options
     */
    public function handle(Invoice $invoice, int $amount, ?string $reason, User $user, array $options = []): void
    {
        $reason = filled($reason) ? trim((string) $reason) : null;

        DB::transaction(function () use ($invoice, $amount, $reason, $user, $options) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            RefundAmounts::check($invoice, ['total' => $amount]);

            // Without taxes given (warranty refunds), the invoice's taxes in proportion.
            $taxes = $options['taxes'] ?? RefundAmounts::calculate($invoice, 'full', 0, [])['taxes'];
            if (! array_key_exists('taxes', $options) || $options['taxes'] === null) {
                $taxes = self::scale($taxes, $amount, max(1, (int) $invoice->amount_paid));
            }

            $left = $amount;
            $originals = $invoice->payments()->valid()->whereNull('refunded_payment_id')->where('amount', '>', 0)
                ->orderByDesc('received_at')->orderByDesc('id')->get();

            foreach ($originals as $payment) {
                if ($left <= 0) {
                    break;
                }

                $refunded = -1 * (int) Payment::query()->valid()->where('refunded_payment_id', $payment->id)->sum('amount');
                $part = min($left, $payment->amount - $refunded);

                if ($part <= 0) {
                    continue;
                }

                // The taxes in this part (the last part takes what is left of them).
                $partTaxes = $part === $left ? $taxes : self::scale($taxes, $part, $left);
                $taxes = self::minus($taxes, $partTaxes);

                $payment->provider !== null && ! ($options['record_only'] ?? false)
                    ? $this->provider($payment, $part, $reason, $partTaxes)
                    : $this->manual($invoice, $payment, $part, $reason, $user, $partTaxes, $options['method'] ?? null);
                $left -= $part;
            }

            if ($left > 0) {
                throw ValidationException::withMessages(['amount' => __('payments.refunds.more_than_paid', ['amount' => Money::format($amount - $left, $invoice->currency, currentCompany()->locale)])]);
            }

            $invoice->credited_amount += $amount;
            $invoice->save();
            $this->syncInvoice->handle($invoice, $user);

            $this->audit->record('invoice.refunded', $invoice, ['number' => $invoice->number, 'amount' => $amount, 'reason' => $reason]);
        });
    }

    /**
     * @param  list<array{name: string, rate: string, amount: int}>  $taxes
     */
    private function provider(Payment $payment, int $amount, ?string $reason, array $taxes): void
    {
        $provider = $this->providers->find($payment->provider);

        if ($provider === null) {
            throw ValidationException::withMessages(['provider' => __('payments.links.no_provider')]);
        }

        try {
            $refundId = $provider->refund($payment, $amount, $reason ?? __('payments.refunds.default_reason'));
        } catch (PaymentProviderException $e) {
            // Shown in the refund window with "Record as cash / e-transfer".
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        $refund = $this->payments->refundFromProvider($payment, $refundId, $amount, now());
        $refund->forceFill(['refund_reason' => $reason, 'refund_taxes' => $taxes, 'refund_status' => $refund->refund_status ?? 'pending'])->save();
    }

    /**
     * @param  list<array{name: string, rate: string, amount: int}>  $taxes
     */
    private function manual(Invoice $invoice, Payment $payment, int $amount, ?string $reason, User $user, array $taxes, ?PaymentMethod $method): void
    {
        $card = in_array($payment->method, [PaymentMethod::Online, PaymentMethod::CardReader, PaymentMethod::CardTerminal], true);
        $method ??= $card ? PaymentMethod::CardTerminal : $payment->method;

        $refund = new Payment([
            'amount' => -$amount,
            'method' => $method,
            'reference' => __('payments.refund_of', ['reference' => $payment->reference ?? $payment->method->label()]),
            'received_at' => now(),
        ]);
        $refund->invoice_id = $invoice->id;
        $refund->estimate_id = $payment->estimate_id;
        $refund->currency = $payment->currency;
        $refund->refunded_payment_id = $payment->id;
        $refund->refund_reason = $reason;
        $refund->refund_taxes = $taxes;
        $refund->refund_status = 'completed';
        $refund->card_brand = $method === PaymentMethod::CardTerminal ? $payment->card_brand : null;
        $refund->card_last4 = $method === PaymentMethod::CardTerminal ? $payment->card_last4 : null;
        $refund->user_id = $user->id;
        $refund->save();

        // Cash handed back leaves the person's cash on hand.
        if ($method === PaymentMethod::Cash) {
            app(CashLedger::class)->collected($refund, $user);
        }
    }

    /**
     * @param  list<array{name: string, rate: string, amount: int}>  $taxes
     * @return list<array{name: string, rate: string, amount: int}>
     */
    private static function scale(array $taxes, int $part, int $whole): array
    {
        return array_map(fn (array $tax) => [...$tax, 'amount' => (int) round($tax['amount'] * $part / max(1, $whole))], $taxes);
    }

    /**
     * @param  list<array{name: string, rate: string, amount: int}>  $taxes
     * @param  list<array{name: string, rate: string, amount: int}>  $part
     * @return list<array{name: string, rate: string, amount: int}>
     */
    private static function minus(array $taxes, array $part): array
    {
        return array_map(fn (array $tax, int $i) => [...$tax, 'amount' => $tax['amount'] - ($part[$i]['amount'] ?? 0)], $taxes, array_keys($taxes));
    }
}
