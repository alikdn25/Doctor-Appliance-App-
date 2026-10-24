<?php

use App\Support\Locale\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Times in texts and print-outs follow PHP's time zone database, like the rest of the app, never ICU's own
 * (often older) one: a December visit in Vancouver must read the same hour as its chip on the job page.
 */
test('the wall-clock hour comes from PHP, not from ICU', function (string $timezone, string $utc) {
    $at = CarbonImmutable::parse($utc, 'UTC');
    $local = $at->setTimezone($timezone);

    expect(LocalTime::format($at, $timezone, 'en-CA', pattern: 'H:mm'))->toBe($local->format('G:i'))
        ->and(LocalTime::format($at, $timezone, 'en-CA', pattern: 'yyyy-MM-dd'))->toBe($local->format('Y-m-d'));
})->with([
    'Vancouver, summer' => ['America/Vancouver', '2026-07-15 20:00'],
    'Vancouver, winter' => ['America/Vancouver', '2026-12-22 21:00'],
    'Vancouver, past midnight UTC' => ['America/Vancouver', '2026-12-23 06:30'],
    'New York, winter' => ['America/New_York', '2026-12-22 21:00'],
    'Kolkata' => ['Asia/Kolkata', '2026-12-22 21:00'],
]);

test('it spells the time in the company\'s regional format', function () {
    $at = CarbonImmutable::parse('2026-07-15 20:00', 'UTC');

    expect(str_replace("\u{202F}", ' ', LocalTime::format($at, 'America/Vancouver', 'en-US')))->toBe('1:00 PM');
});
