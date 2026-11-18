<?php

namespace App\Support\Jobs;

use App\Enums\VisitStatus;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\Property;
use App\Support\Maps\Geocoder;
use Carbon\CarbonImmutable;

/**
 * Windows by area (owner's task of November 19): days that already have a job near the customer's address come first
 * when a window is picked, labelled "Nearby: job in {city}, {N} km". Distances are straight-line between geocoded
 * addresses (the app's map service fills the coordinates of jobs and customers). Without a known position: the usual
 * order. Must run in a tenant context.
 */
class NearbyWindows
{
    public const RADIUS_KM = 15.0;

    public const DAYS = 7;

    /**
     * The position of an address: a saved one, else the typed text through the map service (when it is set up).
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function point(?Property $property = null, ?string $address = null): ?array
    {
        if ($property !== null && $property->latitude !== null && $property->longitude !== null) {
            return ['lat' => (float) $property->latitude, 'lng' => (float) $property->longitude];
        }

        $text = $property?->hasAddress() ? $property->fullAddress() : trim((string) $address);
        if ($text === '' || ! Geocoder::enabled()) {
            return null;
        }
        $found = app(Geocoder::class)->locate($text, currentCompany()->country);

        return $found === null ? null : ['lat' => $found['lat'], 'lng' => $found['lng']];
    }

    /**
     * For each of the next days (company time zone) with a job near the point: the nearest one.
     *
     * @param  array{lat: float, lng: float}|null  $point
     * @return array<string, array{city: string, km: float, label: string}> date => nearest job
     */
    public static function days(Company $company, ?array $point, ?CarbonImmutable $from = null, int $days = self::DAYS): array
    {
        if ($point === null) {
            return [];
        }

        $tz = $company->timezone;
        $from ??= CarbonImmutable::now($tz)->startOfDay();
        $visits = JobVisit::query()->whereIn('status', VisitStatus::openValues())
            ->whereBetween('scheduled_start', [$from->utc(), $from->addDays($days)->utc()])
            ->whereHas('job.property', fn ($q) => $q->whereNotNull('latitude')->whereNotNull('longitude'))
            ->with('job.property')->get();

        $nearest = [];
        foreach ($visits as $visit) {
            $property = $visit->job->property;
            $km = self::km($point, ['lat' => (float) $property->latitude, 'lng' => (float) $property->longitude]);
            if ($km > self::RADIUS_KM) {
                continue;
            }
            $date = CarbonImmutable::instance($visit->scheduled_start)->setTimezone($tz)->toDateString();
            if (! isset($nearest[$date]) || $km < $nearest[$date]['km']) {
                $city = (string) ($property->city ?: $property->line1);
                $nearest[$date] = ['city' => $city, 'km' => round($km, 1), 'label' => self::label($city, $km)];
            }
        }

        return $nearest;
    }

    /**
     * Windows sorted with the nearby days first (nearest first), each nearby one labelled; the rest keep their order.
     *
     * @template T of array{date: string}
     *
     * @param  list<T>  $windows
     * @param  array<string, array{city: string, km: float, label: string}>  $days
     * @return list<T&array{nearby: string|null}>
     */
    public static function rank(array $windows, array $days): array
    {
        $indexed = array_map(fn (array $w, int $i) => [...$w, 'nearby' => $days[$w['date']]['label'] ?? null, '_i' => $i, '_km' => $days[$w['date']]['km'] ?? null], $windows, array_keys($windows));
        usort($indexed, function (array $a, array $b) {
            if (($a['_km'] === null) !== ($b['_km'] === null)) {
                return $a['_km'] === null ? 1 : -1;
            }
            if ($a['_km'] !== null && $a['_km'] !== $b['_km']) {
                return $a['_km'] <=> $b['_km'];
            }

            return $a['_i'] <=> $b['_i'];
        });

        return array_map(function (array $w) {
            unset($w['_i'], $w['_km']);

            return $w;
        }, $indexed);
    }

    /**
     * The company's free arrival windows of the next 7 days (today's windows that have not started yet first), closed
     * days and busy windows left out.
     *
     * @return list<array{date: string, start: string, end: string}>
     */
    public static function freeWindows(Company $company, int $days = self::DAYS): array
    {
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $windows = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $today->addDays($i)->toDateString();
            if (! self::isOpenDay($company, $today->addDays($i))) {
                continue;
            }
            foreach (FreeWindows::on($company, $date) as $window) {
                $windows[] = ['date' => $date, 'start' => $window['start'], 'end' => $window['end']];
            }
        }

        return $windows;
    }

    public static function label(string $city, float $km): string
    {
        return __('booking.nearby', ['city' => $city, 'km' => number_format(max(0.1, $km), $km < 10 ? 1 : 0)]);
    }

    /**
     * @param  array{lat: float, lng: float}  $a
     * @param  array{lat: float, lng: float}  $b
     */
    public static function km(array $a, array $b): float
    {
        $lat = deg2rad($b['lat'] - $a['lat']);
        $lng = deg2rad($b['lng'] - $a['lng']);
        $h = sin($lat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($lng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    private static function isOpenDay(Company $company, CarbonImmutable $day): bool
    {
        $hours = $company->business_hours;
        if (! is_array($hours)) {
            return true;
        }
        $key = strtolower($day->format('D'));

        return ! (bool) ($hours[$key]['closed'] ?? false);
    }
}
