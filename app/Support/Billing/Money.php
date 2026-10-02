<?php

namespace App\Support\Billing;

use App\Support\Locale\Currencies;
use App\Support\Tenancy\CurrentCompany;
use NumberFormatter;

/**
 * Formats an amount in minor units in its currency and a regional format,
 * e.g. (12345, "USD", "en-US") → "$123.45", (12345, "CAD", "en-US") → "CA$123.45".
 */
class Money
{
    public static function format(int $minor, string $currency, ?string $locale = null): string
    {
        $locale ??= app(CurrentCompany::class)->get()?->locale ?? 'en';
        $formatter = new NumberFormatter(str_replace('-', '_', $locale), NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency($minor / Currencies::factor($currency), $currency);
    }
}
