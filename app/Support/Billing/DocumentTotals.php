<?php

namespace App\Support\Billing;

/**
 * Totals of an estimate or invoice, in minor units of the document currency. The React form mirrors this in
 * resources/js/components/billing/money.ts for the live preview; the server result is what is saved.
 *
 * - Line total = quantity × unit price, rounded to the minor unit.
 * - A line with `included` = false (an optional line the customer did not pick) has its line total but does not
 *   count towards the subtotal, discount or taxes.
 * - Discount (fixed amount or percent of the subtotal) is applied before taxes; taxable lines carry
 *   their share of it.
 * - Taxes are applied in order. A normal tax is charged on the discounted taxable amount; a compound tax
 *   on that amount plus the taxes before it. Each tax is rounded to the minor unit.
 * - Prices with tax included (tax-inclusive pricing): line prices already contain the taxes. The taxes are
 *   worked out backwards from the discounted taxable amount and the total is not increased by them.
 */
class DocumentTotals
{
    /**
     * @param  list<array{quantity: string|float|int, unit_price: int, taxable: bool, included?: bool}>  $items
     * @param  list<array{tax_rate_id: int|null, name: string, rate: string|float, compound?: bool}>  $taxes
     * @param  int  $minorFactor  Minor units per major unit of the currency (100 for USD, 1 for JPY), for a fixed discount
     * @return array{
     *     item_totals: list<int>,
     *     subtotal: int,
     *     discount_total: int,
     *     tax_total: int,
     *     total: int,
     *     taxes: list<array{tax_rate_id: int|null, name: string, rate: string, compound: bool, amount: int}>
     * }
     */
    public static function calculate(
        array $items,
        ?string $discountType,
        string|float|int|null $discountValue,
        array $taxes,
        bool $pricesIncludeTax = false,
        int $minorFactor = 100,
    ): array {
        $itemTotals = array_map(fn (array $item) => self::lineTotal($item['quantity'], $item['unit_price']), $items);

        $subtotal = 0;
        $taxableSubtotal = 0;
        foreach ($items as $i => $item) {
            if (! ($item['included'] ?? true)) {
                continue;
            }

            $subtotal += $itemTotals[$i];

            if ($item['taxable']) {
                $taxableSubtotal += $itemTotals[$i];
            }
        }

        $discount = self::discount($subtotal, $discountType, (float) ($discountValue ?? 0), $minorFactor);
        $taxableDiscount = $subtotal > 0 ? (int) round($discount * $taxableSubtotal / $subtotal) : 0;
        $taxable = max(0, $taxableSubtotal - $taxableDiscount);

        $amounts = $pricesIncludeTax ? self::includedTaxes($taxable, $taxes) : self::addedTaxes($taxable, $taxes);

        $taxLines = array_map(fn (array $tax, int $amount) => [
            'tax_rate_id' => $tax['tax_rate_id'] ?? null,
            'name' => $tax['name'],
            'rate' => self::rate($tax['rate']),
            'compound' => (bool) ($tax['compound'] ?? false),
            'amount' => $amount,
        ], $taxes, $amounts);

        $taxTotal = array_sum($amounts);

        return [
            'item_totals' => $itemTotals,
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $taxTotal,
            'total' => $subtotal - $discount + ($pricesIncludeTax ? 0 : $taxTotal),
            'taxes' => $taxLines,
        ];
    }

    public static function lineTotal(string|float|int $quantity, int $unitPrice): int
    {
        return (int) round((float) $quantity * $unitPrice);
    }

    /**
     * Taxes on top of a net amount.
     *
     * @param  list<array{rate: string|float, compound?: bool}>  $taxes
     * @return list<int>
     */
    private static function addedTaxes(int $net, array $taxes): array
    {
        $amounts = [];
        $running = $net;

        foreach ($taxes as $tax) {
            $base = ($tax['compound'] ?? false) ? $running : $net;
            $amount = (int) round($base * (float) $tax['rate'] / 100);
            $amounts[] = $amount;
            $running += $amount;
        }

        return $amounts;
    }

    /**
     * Taxes contained in a gross amount: find the net amount, tax it, and give any rounding difference to the
     * last tax so that net + taxes = gross exactly.
     *
     * @param  list<array{rate: string|float, compound?: bool}>  $taxes
     * @return list<int>
     */
    private static function includedTaxes(int $gross, array $taxes): array
    {
        if ($taxes === [] || $gross === 0) {
            return array_fill(0, count($taxes), 0);
        }

        // Gross of one unit of net, applying the taxes the same way as addedTaxes().
        $factor = 1.0;
        foreach ($taxes as $tax) {
            $rate = (float) $tax['rate'] / 100;
            $factor += ($tax['compound'] ?? false) ? $factor * $rate : $rate;
        }

        $net = (int) round($gross / $factor);
        $amounts = self::addedTaxes($net, $taxes);
        $amounts[count($amounts) - 1] += $gross - $net - array_sum($amounts);

        return $amounts;
    }

    private static function discount(int $subtotal, ?string $type, float $value, int $minorFactor): int
    {
        if ($subtotal <= 0 || $value <= 0) {
            return 0;
        }

        $discount = match ($type) {
            'percent' => (int) round($subtotal * min($value, 100) / 100),
            'amount' => (int) round($value * $minorFactor),
            default => 0,
        };

        return min($discount, $subtotal);
    }

    /**
     * "5.0000" → "5", "7.5000" → "7.5" (as shown on documents).
     */
    private static function rate(string|float $rate): string
    {
        $formatted = rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
