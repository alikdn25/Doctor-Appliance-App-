import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/** Cents → "$1,234.50" in the company's currency. */
export function formatMoney(cents: number, currency = 'CAD'): string {
    return new Intl.NumberFormat('en-CA', {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
    }).format(cents / 100);
}

export function useMoney() {
    const { auth } = usePage().props;
    const currency = auth.company?.currency ?? 'CAD';

    return useCallback(
        (cents: number) => formatMoney(cents, currency),
        [currency],
    );
}

/** "12.5" → 1250. Empty or invalid input counts as 0. */
export function toCents(dollars: string): number {
    const value = Number.parseFloat(dollars.replace(/[$,\s]/g, ''));

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

/** 1250 → "12.50" for inputs. */
export function toDollars(cents: number): string {
    return (cents / 100).toFixed(2);
}

export type TotalsInput = {
    items: { quantity: string; unit_price: string; taxable: boolean }[];
    discount_type: '' | 'amount' | 'percent';
    discount_value: string;
    taxes: { name: string; rate: string }[];
};

export type Totals = {
    itemTotals: number[];
    subtotal: number;
    discount: number;
    taxes: { name: string; rate: string; amount: number }[];
    total: number;
};

/**
 * Live preview of the totals. Mirrors App\Support\Billing\DocumentTotals; the server's numbers are what is saved.
 */
export function computeTotals(input: TotalsInput): Totals {
    const itemTotals = input.items.map((item) =>
        Math.round(
            (Number.parseFloat(item.quantity) || 0) * toCents(item.unit_price),
        ),
    );
    const subtotal = itemTotals.reduce((sum, value) => sum + value, 0);
    const taxableSubtotal = input.items.reduce(
        (sum, item, i) => sum + (item.taxable ? itemTotals[i] : 0),
        0,
    );

    const value = Number.parseFloat(input.discount_value) || 0;
    let discount = 0;

    if (subtotal > 0 && value > 0) {
        if (input.discount_type === 'percent') {
            discount = Math.round((subtotal * Math.min(value, 100)) / 100);
        } else if (input.discount_type === 'amount') {
            discount = Math.round(value * 100);
        }

        discount = Math.min(discount, subtotal);
    }

    const taxableDiscount =
        subtotal > 0 ? Math.round((discount * taxableSubtotal) / subtotal) : 0;
    const base = Math.max(0, taxableSubtotal - taxableDiscount);
    const taxes = input.taxes.map((tax) => ({
        name: tax.name,
        rate: tax.rate,
        amount: Math.round((base * Number.parseFloat(tax.rate)) / 100),
    }));

    return {
        itemTotals,
        subtotal,
        discount,
        taxes,
        total:
            subtotal -
            discount +
            taxes.reduce((sum, tax) => sum + tax.amount, 0),
    };
}
