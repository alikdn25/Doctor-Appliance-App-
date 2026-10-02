<?php

namespace App\Support\Locale;

/**
 * Formats an address on one line in the order of its country (config/countries.php):
 *   US/CA/AU: "123 Main St, Surrey, BC V3T 1A1"
 *   UK/IE:    "10 Downing St, London, SW1A 2AA"
 *   DE/FR…:   "Hauptstr. 5, 10115 Berlin"
 */
class AddressFormatter
{
    public static function oneLine(string $street, ?string $city, ?string $region, ?string $postal, ?string $country): string
    {
        $order = Countries::addressFormat($country)['order'];

        $locality = match ($order) {
            'postal_city' => [trim("{$postal} {$city}"), $region],
            'city_postal' => [$city, $region, $postal],
            default => [$city, trim("{$region} {$postal}")],
        };

        return collect([$street, ...$locality])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');
    }
}
