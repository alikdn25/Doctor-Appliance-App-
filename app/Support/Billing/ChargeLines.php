<?php

namespace App\Support\Billing;

use App\Models\Company;
use App\Models\TaxRate;
use App\Support\Locale\Currencies;
use Illuminate\Support\Collection;

/**
 * Lines typed on New charge and in "Close by voice" (owner's task of November 22): each line has its own amount and
 * its own taxes (a tick per company tax). The amounts are all with tax or all without tax:
 * - without tax: each line's ticked taxes are added on top;
 * - with tax: each line already contains its ticked taxes; they are worked back out of it, and the total is what
 *   was typed.
 * All money is in minor units; each tax is rounded once, on the total (DocumentTotals). The taxes are the company's
 * (never hard-coded); on a new line only the first of them is ticked. Must run in a tenant context.
 */
class ChargeLines
{
    /**
     * The company's taxes a line can have: active default rates, compound ones last.
     *
     * @return list<array{tax_rate_id: int, name: string, rate: string, compound: bool}>
     */
    public static function taxes(): array
    {
        /** @var Collection<int, TaxRate> $rates */
        $rates = TaxRate::query()->where('is_active', true)->where('is_default', true)
            ->orderBy('is_compound')->orderBy('sort_order')->orderBy('name')->get();

        return $rates->map(fn (TaxRate $rate) => [
            'tax_rate_id' => $rate->id,
            'name' => $rate->name,
            'rate' => (string) $rate->rate,
            'compound' => $rate->is_compound,
        ])->values()->all();
    }

    /**
     * Taxes ticked on a new line: the first of the company's taxes.
     *
     * @param  list<array{tax_rate_id: int}>|null  $taxes
     * @return list<int>
     */
    public static function defaultIds(?array $taxes = null): array
    {
        $taxes ??= self::taxes();

        return $taxes === [] ? [] : [(int) $taxes[0]['tax_rate_id']];
    }

    /**
     * @param  list<array<string, mixed>>  $lines  description, amount (major units), tax_rate_ids (missing = all taxes), kind…
     * @return array{lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>, subtotal: int, tax_total: int, total: int, currency: string, tax_rate_ids: list<int>}
     */
    public static function totals(array $lines, bool $amountsIncludeTax, Company $company): array
    {
        $taxes = self::taxes();
        $known = array_column($taxes, 'tax_rate_id');
        $factor = Currencies::factor($company->currency);
        $pricesIncludeTax = (bool) $company->prices_include_tax;

        $lines = array_values(array_map(function (array $line) use ($known) {
            $ids = array_key_exists('tax_rate_ids', $line) && is_array($line['tax_rate_ids'])
                ? array_map('intval', $line['tax_rate_ids'])
                : $known; // A line saved before the ticks (e.g. a pending reader charge): all taxes, as then.

            // Only this company's taxes, in the company's order.
            return [...$line, 'tax_rate_ids' => array_values(array_filter($known, fn (int $id) => in_array($id, $ids, true)))];
        }, $lines));

        $amounts = array_map(fn (array $line) => (int) round(((float) $line['amount']) * $factor), $lines);
        $ids = array_column($lines, 'tax_rate_ids');
        $calculate = fn (array $prices) => DocumentTotals::calculate(
            array_map(fn (int $price, array $lineIds) => ['quantity' => 1, 'unit_price' => $price, 'taxable' => $lineIds !== [], 'tax_rate_ids' => $lineIds], $prices, $ids),
            null, 0, $taxes, $pricesIncludeTax, $factor,
        );

        $prices = self::prices($amounts, $ids, $taxes, $amountsIncludeTax, $pricesIncludeTax, $calculate);
        $totals = $calculate($prices);

        $used = array_values(array_filter($known, fn (int $id) => in_array($id, array_merge([], ...$ids), true)));

        return [
            'lines' => array_map(fn (array $line, int $price, int $total) => [...$line, 'unit_price' => $price, 'total' => $total], $lines, $prices, $totals['item_totals']),
            'taxes' => array_values(array_filter($totals['taxes'], fn (array $tax) => in_array((int) $tax['tax_rate_id'], $used, true))),
            'subtotal' => $pricesIncludeTax ? $totals['total'] - $totals['tax_total'] : $totals['subtotal'],
            'tax_total' => $totals['tax_total'],
            'total' => $totals['total'],
            'currency' => $company->currency,
            'tax_rate_ids' => $used,
        ];
    }

    /**
     * Line prices in the invoice's price mode (the company's). Amounts with tax on an invoice without tax in its
     * prices: each line's net worked back from its own taxes, then moved by a cent or two so that the invoice total
     * is exactly what was typed. Amounts without tax on an invoice with tax in its prices: each line's taxes added.
     *
     * @param  list<int>  $amounts
     * @param  list<list<int>>  $ids
     * @param  list<array{tax_rate_id: int, rate: string, compound: bool}>  $taxes
     * @param  callable(list<int>): array{total: int}  $calculate
     * @return list<int>
     */
    private static function prices(array $amounts, array $ids, array $taxes, bool $amountsIncludeTax, bool $pricesIncludeTax, callable $calculate): array
    {
        if ($amountsIncludeTax === $pricesIncludeTax) {
            return $amounts;
        }

        $gross = fn (array $lineIds) => self::grossFactor($taxes, $lineIds);

        if (! $amountsIncludeTax) {
            return array_map(fn (int $amount, array $lineIds) => (int) round($amount * $gross($lineIds)), $amounts, $ids);
        }

        $prices = array_map(fn (int $amount, array $lineIds) => (int) round($amount / $gross($lineIds)), $amounts, $ids);
        $target = array_sum($amounts);
        if ($calculate($prices)['total'] === $target) {
            return $prices;
        }

        // Rounding of the taxes moved the total by a cent: the largest taxed lines take the difference.
        $taxed = array_keys(array_filter($ids, fn (array $lineIds) => $lineIds !== []));
        usort($taxed, fn (int $a, int $b) => $amounts[$b] <=> $amounts[$a]);
        foreach (array_slice($taxed, 0, 3) as $index) {
            foreach ([-1, 1, -2, 2, -3, 3] as $step) {
                $try = $prices;
                $try[$index] += $step;
                if ($try[$index] >= 0 && $calculate($try)['total'] === $target) {
                    return $try;
                }
            }
        }

        return $prices;
    }

    /**
     * Gross of one unit of net with the given taxes (a compound tax on the net plus the taxes before it).
     *
     * @param  list<array{tax_rate_id: int, rate: string, compound: bool}>  $taxes
     * @param  list<int>  $ids
     */
    private static function grossFactor(array $taxes, array $ids): float
    {
        $gross = 1.0;
        foreach ($taxes as $tax) {
            if (in_array((int) $tax['tax_rate_id'], $ids, true)) {
                $rate = (float) $tax['rate'] / 100;
                $gross += $tax['compound'] ? $gross * $rate : $rate;
            }
        }

        return $gross;
    }
}
