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

    /**
     * Street part of a business address typed by hand: "140 6th St, New Westminster, V3l2z9" + "514" →
     * "Unit 514, 140 6th St". The city and postal code typed into the street line are left out (they are shown
     * once, from their own fields), and a bare suite number goes first as a unit.
     */
    public static function street(?string $line1, ?string $line2, ?string $city, ?string $country): string
    {
        $pattern = Countries::addressFormat($country)['postal_pattern'] ?? null;
        $city = mb_strtolower(trim((string) $city));

        $parts = collect(explode(',', (string) $line1))
            ->map(fn ($part) => trim($part))
            ->filter()
            ->values();
        $parts = $parts->reject(fn (string $part, int $i) => $i > 0 && (
            mb_strtolower($part) === $city || ($pattern && preg_match($pattern, $part))
        ));

        $line2 = trim((string) $line2);
        $unit = preg_match('/^[\p{L}\d-]{1,6}$/u', $line2) && preg_match('/\d/', $line2)
            ? __('properties.unit_prefix', ['unit' => $line2])
            : null;

        return collect([$unit, ...$parts, $unit ? null : $line2])->filter()->implode(', ');
    }

    /**
     * Postal codes are written in capitals ("v3l 2z9" → "V3L 2Z9").
     */
    public static function postal(?string $postal): ?string
    {
        return $postal === null ? null : mb_strtoupper(trim($postal));
    }
}
