<?php

namespace App\Support\Billing;

use App\Models\Invoice;
use App\Models\TaxRate;
use Illuminate\Validation\ValidationException;

/**
 * The money of a refund (owner's task of November 26), worked out by code:
 * - Full refund: everything paid that is not refunded yet (amount_paid is net of earlier refunds), with the invoice's
 *   taxes in proportion.
 * - Partial refund: an amount without taxes; each ticked tax (the company's rates, ticked as they were on the invoice)
 *   is added on top. A refund can never exceed what is left to refund.
 */
class RefundAmounts
{
    /**
     * The taxes offered in the refund window: every active company rate, ticked when the invoice charged it, plus the
     * invoice's own taxes that are no longer a company rate.
     *
     * @return list<array{name: string, rate: string, compound: bool, checked: bool}>
     */
    public static function taxOptions(Invoice $invoice): array
    {
        $charged = collect((array) $invoice->taxes)->filter(fn (array $tax) => (int) ($tax['amount'] ?? 0) > 0);
        $options = TaxRate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (TaxRate $rate) => [
                'name' => $rate->name,
                'rate' => DocumentTotals::rateLabel($rate->rate),
                'compound' => (bool) $rate->is_compound,
                'checked' => $charged->contains(fn (array $tax) => $tax['name'] === $rate->name),
            ]);

        foreach ($charged as $tax) {
            if (! $options->contains(fn (array $option) => $option['name'] === $tax['name'])) {
                $options->push(['name' => (string) $tax['name'], 'rate' => DocumentTotals::rateLabel($tax['rate'] ?? 0), 'compound' => (bool) ($tax['compound'] ?? false), 'checked' => true]);
            }
        }

        return $options->values()->all();
    }

    /**
     * @param  list<string>  $taxNames  Partial refund: the ticked taxes
     * @return array{total: int, base: int, taxes: list<array{name: string, rate: string, amount: int}>}
     */
    public static function calculate(Invoice $invoice, string $mode, int $base, array $taxNames): array
    {
        $left = max(0, (int) $invoice->amount_paid);

        if ($mode === 'full') {
            $taxes = $invoice->total > 0
                ? collect((array) $invoice->taxes)
                    ->map(fn (array $tax) => ['name' => (string) $tax['name'], 'rate' => DocumentTotals::rateLabel($tax['rate'] ?? 0), 'amount' => (int) round((int) ($tax['amount'] ?? 0) * $left / $invoice->total)])
                    ->filter(fn (array $tax) => $tax['amount'] > 0)->values()->all()
                : [];

            return ['total' => $left, 'base' => $left - array_sum(array_column($taxes, 'amount')), 'taxes' => $taxes];
        }

        $selected = collect(self::taxOptions($invoice))->filter(fn (array $tax) => in_array($tax['name'], $taxNames, true))->values();
        $totals = DocumentTotals::calculate(
            [['quantity' => 1, 'unit_price' => max(0, $base), 'taxable' => true]],
            null,
            null,
            $selected->map(fn (array $tax) => ['tax_rate_id' => null, 'name' => $tax['name'], 'rate' => $tax['rate'], 'compound' => $tax['compound']])->all(),
        );

        return [
            'total' => $totals['total'],
            'base' => max(0, $base),
            'taxes' => collect($totals['taxes'])->map(fn (array $tax) => ['name' => $tax['name'], 'rate' => $tax['rate'], 'amount' => $tax['amount']])
                ->filter(fn (array $tax) => $tax['amount'] > 0)->values()->all(),
        ];
    }

    /**
     * @param  array{total: int}  $refund
     */
    public static function check(Invoice $invoice, array $refund): void
    {
        if ($refund['total'] <= 0) {
            throw ValidationException::withMessages(['amount' => __('payments.errors.amount_required')]);
        }

        if ($invoice->isVoid() || $refund['total'] > $invoice->amount_paid) {
            throw ValidationException::withMessages(['amount' => __('payments.refunds.more_than_paid', [
                'amount' => Money::format(max(0, (int) $invoice->amount_paid), $invoice->currency, currentCompany()->locale),
            ])]);
        }
    }
}
