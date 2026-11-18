<?php

namespace App\Support\Locale;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The company's business hours (Company settings → Business hours) in its own time zone: whether it is open at a
 * moment, and when it next opens. Reminders and auto replies only happen while the company is open.
 */
class BusinessHours
{
    public static function isOpen(Company $company, CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone($company->timezone);
        $day = self::day($company, $local);

        if ($day === null) {
            return false;
        }

        $time = $local->format('H:i');

        return $time >= $day['open'] && $time < $day['close'];
    }

    /**
     * The moment itself when the company is open then, otherwise the start of the next opening (UTC).
     */
    public static function nextOpen(Company $company, CarbonInterface $at): CarbonImmutable
    {
        if (self::isOpen($company, $at)) {
            return CarbonImmutable::instance($at)->utc();
        }

        $local = CarbonImmutable::instance($at)->setTimezone($company->timezone);

        for ($i = 0; $i <= 7; $i++) {
            $date = $local->startOfDay()->addDays($i);
            $day = self::day($company, $date);

            if ($day === null) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $day['open']));
            $opens = $date->setTime($hour, $minute);

            if ($opens->greaterThan($local)) {
                return $opens->utc();
            }
        }

        // A company closed every day: never hold anything back forever.
        return $local->utc();
    }

    /**
     * @return array{open: string, close: string}|null
     */
    private static function day(Company $company, CarbonImmutable $local): ?array
    {
        $hours = $company->business_hours ?? Company::defaultBusinessHours();
        $day = $hours[strtolower($local->format('D'))] ?? null;

        if (! is_array($day) || ($day['closed'] ?? false) || empty($day['open']) || empty($day['close'])) {
            return null;
        }

        return ['open' => substr((string) $day['open'], 0, 5), 'close' => substr((string) $day['close'], 0, 5)];
    }
}
