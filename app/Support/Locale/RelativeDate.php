<?php

namespace App\Support\Locale;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * A date the AI read from a text: either YYYY-MM-DD, or a word like "tomorrow" / "Thursday" / "завтра" / "в четверг",
 * turned into a date by code in the company's time zone (a weekday means the next such day after today).
 */
class RelativeDate
{
    private const WEEKDAYS = [
        'monday' => 1, 'mon' => 1, 'понедельник' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2, 'вторник' => 2,
        'wednesday' => 3, 'wed' => 3, 'среда' => 3, 'среду' => 3,
        'thursday' => 4, 'thu' => 4, 'thurs' => 4, 'четверг' => 4,
        'friday' => 5, 'fri' => 5, 'пятница' => 5, 'пятницу' => 5,
        'saturday' => 6, 'sat' => 6, 'суббота' => 6, 'субботу' => 6,
        'sunday' => 7, 'sun' => 7, 'воскресенье' => 7,
    ];

    public static function resolve(?string $value, Company $company, ?CarbonImmutable $now = null): ?string
    {
        $value = mb_strtolower(trim((string) $value));
        $today = ($now ?? CarbonImmutable::now())->setTimezone($company->timezone)->startOfDay();

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $company->timezone);

            return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
        }

        $words = trim((string) preg_replace('/^(on|this|next|в|во|на|в эту|в этот|в следующий|в следующую)\s+/u', '', $value));

        return match (true) {
            in_array($words, ['today', 'tonight', 'now', 'сегодня', 'сейчас'], true) => $today->toDateString(),
            in_array($words, ['tomorrow', 'завтра'], true) => $today->addDay()->toDateString(),
            in_array($words, ['day after tomorrow', 'the day after tomorrow', 'послезавтра'], true) => $today->addDays(2)->toDateString(),
            isset(self::WEEKDAYS[$words]) => self::nextWeekday($today, self::WEEKDAYS[$words]),
            default => null,
        };
    }

    private static function nextWeekday(CarbonImmutable $today, int $isoDay): string
    {
        $days = ($isoDay - $today->dayOfWeekIso + 7) % 7;

        return $today->addDays($days === 0 ? 7 : $days)->toDateString();
    }
}
