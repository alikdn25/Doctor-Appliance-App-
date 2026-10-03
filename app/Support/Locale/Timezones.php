<?php

namespace App\Support\Locale;

use DateTimeImmutable;
use DateTimeZone;

class Timezones
{
    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        $now = new DateTimeImmutable('now');
        $options = [];
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $zone = new DateTimeZone($identifier);
            $minutes = intdiv($zone->getOffset($now), 60);
            $offset = sprintf('UTC%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
            $location = $zone->getLocation();
            $city = str_replace('_', ' ', substr(strrchr($identifier, '/') ?: '/'.$identifier, 1));
            $country = $location && $location['country_code'] !== '??' ? Countries::name($location['country_code']) : '';
            $options[] = ['value' => $identifier, 'label' => $identifier === 'UTC' ? 'UTC+00:00 — Coordinated Universal Time' : $offset.' — '.$city.($country !== '' ? ', '.$country : ''), 'offset' => $minutes];
        }
        usort($options, fn ($a, $b) => [$a['offset'], $a['label']] <=> [$b['offset'], $b['label']]);

        return array_map(fn ($option) => ['value' => $option['value'], 'label' => $option['label']], $options);
    }
}
