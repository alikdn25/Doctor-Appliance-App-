<?php

namespace App\Support\Billing;

/**
 * Totals of an estimate or invoice, in cents. The React form mirrors this in
 * resources/js/components/billing/money.ts for the live preview; the server result is what is saved.
 *
 * - Line total = quantity × unit price, rounded to the cent.
 * - Discount (fixed amount or percent of the subtotal) is applied before taxes; taxable lines carry
 *   their share of it.
 * - Each tax is calculated once on the discounted taxable amount and rounded to the cent.
 */
class DocumentTotals
{
    /**
     * @param  list<array{quantity: string|float|int, unit_price: int, taxable: bool}>  $items
     * @param  list<array{tax_rate_id: int|null, name: string, rate: string|float}>  $taxes
     * @return array{
     *     item_totals: list<int>,
     *     subtotal: int,
     *     discount_total: int,
     *     tax_total: int,
     *     total: int,
     *     taxes: list<array{tax_rate_id: int|null, name: string, rate: string, amount: int}>
     * }
     */
    public static function calculate(array $items, ?string $discountType, string|float|int|null $discountValue, array $taxes): array
    {
        $itemTotals = array_map(fn (array $item) => self::lineTotal($item['quantity'], $item['unit_price']), $items);

        $subtotal = array_sum($itemTotals);
        $taxableSubtotal = 0;
        foreach ($items as $i => $item) {
            if ($item['taxable']) {
                $taxableSubtotal += $itemTotals[$i];
            }
        }

        $discount = self::discount($subtotal, $discountType, (float) ($discountValue ?? 0));
        $taxableDiscount = $subtotal > 0 ? (int) round($discount * $taxableSubtotal / $subtotal) : 0;
        $taxBase = max(0, $taxableSubtotal - $taxableDiscount);

        $taxLines = array_map(fn (array $tax) => [
            'tax_rate_id' => $tax['tax_rate_id'] ?? null,
            'name' => $tax['name'],
            'rate' => self::rate($tax['rate']),
            'amount' => (int) round($taxBase * (float) $tax['rate'] / 100),
        ], $taxes);

        $taxTotal = array_sum(array_column($taxLines, 'amount'));

        return [
            'item_totals' => $itemTotals,
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $taxTotal,
            'total' => $subtotal - $discount + $taxTotal,
            'taxes' => $taxLines,
        ];
    }

    public static function lineTotal(string|float|int $quantity, int $unitPrice): int
    {
        return (int) round((float) $quantity * $unitPrice);
    }

    private static function discount(int $subtotal, ?string $type, float $value): int
    {
        if ($subtotal <= 0 || $value <= 0) {
            return 0;
        }

        $discount = match ($type) {
            'percent' => (int) round($subtotal * min($value, 100) / 100),
            'amount' => (int) round($value * 100),
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
