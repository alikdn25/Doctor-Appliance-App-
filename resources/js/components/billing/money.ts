import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * Money on the client. Amounts are integers in the currency's minor units (cents for USD/CAD/EUR,
 * whole yen for JPY). Symbols and number format follow the company's regional format; nothing is hard-coded.
 */

/** Digits after the decimal point: 2 for USD, 0 for JPY, 3 for KWD. */
export function currencyDecimals(currency: string): number {
    return (
        new Intl.NumberFormat('en', {
            style: 'currency',
            currency,
        }).resolvedOptions().maximumFractionDigits ?? 2
    );
}

/** Minor units → "$1,234.50" / "CA$1,234.50" / "£1,234.50" / "¥1,235". */
export function formatMoney(
    minor: number,
    currency: string,
    locale = 'en-US',
): string {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(
        minor / 10 ** currencyDecimals(currency),
    );
}

/**
 * Formatter in the company's regional format. Pass the document's currency where there is one
 * (documents keep the currency they were created in); otherwise the company's currency is used.
 */
export function useMoney(currency?: string) {
    const { auth } = usePage().props;
    const fallback = currency ?? auth.company?.currency ?? 'USD';
    const locale = auth.company?.locale ?? 'en-US';

    return useCallback(
        (minor: number, override?: string) =>
            formatMoney(minor, override ?? fallback, locale),
        [fallback, locale],
    );
}

/** The currency symbol alone in the company's regional format, e.g. "$", "CA$", "€". */
export function currencySymbol(currency: string, locale = 'en-US'): string {
    return (
        new Intl.NumberFormat(locale, { style: 'currency', currency })
            .formatToParts(0)
            .find((part) => part.type === 'currency')?.value ?? currency
    );
}

/** "12.5" → 1250 (USD), "1200" → 1200 (JPY). Empty or invalid input counts as 0. */
export function toMinor(amount: string, currency: string): number {
    const value = Number.parseFloat(amount.replace(/[^\d.-]/g, ''));

    return Number.isFinite(value)
        ? Math.round(value * 10 ** currencyDecimals(currency))
        : 0;
}

/** 1250 → "12.50" (USD) for inputs. */
export function fromMinor(minor: number, currency: string): string {
    const decimals = currencyDecimals(currency);

    return (minor / 10 ** decimals).toFixed(decimals);
}

export type TotalsInput = {
    items: { quantity: string; unit_price: string; taxable: boolean }[];
    discount_type: '' | 'amount' | 'percent';
    discount_value: string;
    taxes: { name: string; rate: string; compound?: boolean }[];
    currency: string;
    prices_include_tax: boolean;
};

export type Totals = {
    itemTotals: number[];
    subtotal: number;
    discount: number;
    taxes: { name: string; rate: string; amount: number }[];
    total: number;
};

function addedTaxes(
    net: number,
    taxes: TotalsInput['taxes'],
): number[] {
    let running = net;

    return taxes.map((tax) => {
        const base = tax.compound ? running : net;
        const amount = Math.round(
            (base * (Number.parseFloat(tax.rate) || 0)) / 100,
        );
        running += amount;

        return amount;
    });
}

function includedTaxes(
    gross: number,
    taxes: TotalsInput['taxes'],
): number[] {
    if (taxes.length === 0 || gross === 0) {
        return taxes.map(() => 0);
    }

    let factor = 1;

    for (const tax of taxes) {
        const rate = (Number.parseFloat(tax.rate) || 0) / 100;
        factor += tax.compound ? factor * rate : rate;
    }

    const net = Math.round(gross / factor);
    const amounts = addedTaxes(net, taxes);
    amounts[amounts.length - 1] +=
        gross - net - amounts.reduce((sum, value) => sum + value, 0);

    return amounts;
}

/**
 * Live preview of the totals. Mirrors App\Support\Billing\DocumentTotals; the server's numbers are what is saved.
 */
export function computeTotals(input: TotalsInput): Totals {
    const factor = 10 ** currencyDecimals(input.currency);
    const itemTotals = input.items.map((item) =>
        Math.round(
            (Number.parseFloat(item.quantity) || 0) *
                toMinor(item.unit_price, input.currency),
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
            discount = Math.round(value * factor);
        }

        discount = Math.min(discount, subtotal);
    }

    const taxableDiscount =
        subtotal > 0 ? Math.round((discount * taxableSubtotal) / subtotal) : 0;
    const base = Math.max(0, taxableSubtotal - taxableDiscount);
    const amounts = input.prices_include_tax
        ? includedTaxes(base, input.taxes)
        : addedTaxes(base, input.taxes);
    const taxTotal = amounts.reduce((sum, amount) => sum + amount, 0);

    return {
        itemTotals,
        subtotal,
        discount,
        taxes: input.taxes.map((tax, i) => ({
            name: tax.name,
            rate: tax.rate,
            amount: amounts[i],
        })),
        total: subtotal - discount + (input.prices_include_tax ? 0 : taxTotal),
    };
}
