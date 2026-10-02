<?php

namespace App\Messaging;

use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonInterface;
use IntlDateFormatter;

/**
 * Values for the template placeholders, formatted in the company's regional format and time zone.
 * Must run in a tenant context.
 */
class MessageContext
{
    /**
     * @return array<string, string|null>
     */
    public static function for(Customer $customer, ?ServiceJob $job = null, ?JobVisit $visit = null, ?User $tech = null): array
    {
        $company = currentCompany();
        $job?->loadMissing('brand');

        return [
            'customer_first_name' => $customer->first_name ?: $customer->display_name,
            'customer_name' => $customer->display_name,
            'brand' => $job?->brand?->name ?? $company->name,
            'company' => $company->name,
            'tech_name' => $tech?->name ? strtok($tech->name, ' ') : null,
            'visit_date' => $visit ? self::date($visit->scheduled_start) : null,
            'arrival_window' => $visit ? self::window($visit) : null,
        ];
    }

    public static function date(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $company = currentCompany();
        $formatter = new IntlDateFormatter(str_replace('-', '_', $company->locale), IntlDateFormatter::FULL, IntlDateFormatter::NONE, $company->timezone, null, 'EEEE, MMM d');

        return self::plain((string) $formatter->format($at));
    }

    public static function time(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $company = currentCompany();
        $formatter = new IntlDateFormatter(str_replace('-', '_', $company->locale), IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $company->timezone);

        return self::plain((string) $formatter->format($at));
    }

    public static function window(JobVisit $visit): string
    {
        return trim(self::time($visit->scheduled_start).' - '.self::time($visit->scheduled_end), ' -');
    }

    /**
     * ICU puts narrow/no-break spaces in times ("1:00 p.m."); texts use plain spaces so they stay in the
     * GSM-7 alphabet (a single non-GSM character makes every SMS segment shorter and dearer).
     */
    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{00A0}", "\u{2009}"], ' ', $text);
    }
}
