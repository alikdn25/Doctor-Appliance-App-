<?php

namespace App\Support\Locale;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;

/**
 * Spells a moment in a company's time zone and regional format.
 *
 * PHP (Carbon) and ICU (intl) each ship their own time zone database, and the ICU one is often years older
 * (e.g. it does not know that a region stopped changing its clocks). Letting ICU convert the time zone then
 * puts the hour off by one next to the rest of the app. So PHP works out the wall-clock time and ICU only
 * spells it, treating that wall-clock time as UTC.
 */
class LocalTime
{
    public static function format(
        DateTimeInterface $at,
        string $timezone,
        string $locale,
        int $dateType = IntlDateFormatter::NONE,
        int $timeType = IntlDateFormatter::SHORT,
        ?string $pattern = null,
    ): string {
        $local = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone($timezone));
        $wall = new DateTimeImmutable($local->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        $formatter = new IntlDateFormatter(str_replace('-', '_', $locale), $dateType, $timeType, 'UTC', null, $pattern);

        return (string) $formatter->format($wall);
    }
}
