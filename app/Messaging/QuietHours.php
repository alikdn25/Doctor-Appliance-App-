<?php

namespace App\Messaging;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * No texts at night (company time zone): a message due in the quiet hours waits until they end.
 * Quiet hours may span midnight (21:00–08:00, the default) or not (00:00–07:00).
 */
class QuietHours
{
    public static function nextAllowed(Company $company, ?CarbonInterface $at = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at ?? now())->setTimezone($company->timezone);
        [$startH, $startM] = array_map('intval', explode(':', $company->quiet_hours_start));
        [$endH, $endM] = array_map('intval', explode(':', $company->quiet_hours_end));

        $start = $startH * 60 + $startM;
        $end = $endH * 60 + $endM;
        $now = $local->hour * 60 + $local->minute;

        if ($start === $end) {
            return $local->utc();
        }

        $quiet = $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);

        if (! $quiet) {
            return $local->utc();
        }

        $morning = $local->setTime($endH, $endM);
        if ($morning->lessThanOrEqualTo($local)) {
            $morning = $morning->addDay();
        }

        return $morning->utc();
    }
}
