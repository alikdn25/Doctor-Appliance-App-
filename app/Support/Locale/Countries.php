<?php

namespace App\Support\Locale;

use DateTimeZone;
use libphonenumber\PhoneNumberUtil;
use Locale;
use NumberFormatter;

/**
 * Countries a company can be in and the defaults a country gives it (SPEC §1.1): currency,
 * regional format, time zone and address format. Built from ICU data plus config/countries.php.
 */
class Countries
{
    /** @var list<array{value: string, label: string}>|null Computed once per process. */
    private static ?array $options = null;

    /**
     * ISO 3166-1 alpha-2 codes, sorted by name (pinned countries first).
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::options(), 'value');
    }

    public static function exists(?string $code): bool
    {
        return $code !== null && in_array(strtoupper($code), self::codes(), true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return self::$options ??= (function () {
            $codes = array_filter(
                PhoneNumberUtil::getInstance()->getSupportedRegions(),
                fn (string $code) => preg_match('/^[A-Z]{2}$/', $code) === 1,
            );

            $options = array_map(fn (string $code) => ['value' => $code, 'label' => self::name($code)], $codes);
            usort($options, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

            $pinned = (array) config('countries.pinned', []);
            $first = array_values(array_filter($options, fn ($o) => in_array($o['value'], $pinned, true)));
            usort($first, fn ($a, $b) => array_search($a['value'], $pinned, true) <=> array_search($b['value'], $pinned, true));

            return [...$first, ...array_values(array_filter($options, fn ($o) => ! in_array($o['value'], $pinned, true)))];
        })();
    }

    public static function name(string $code): string
    {
        return Locale::getDisplayRegion('-'.strtoupper($code), 'en') ?: strtoupper($code);
    }

    /**
     * The currency used in the country (ISO 4217), e.g. "CA" → "CAD".
     */
    public static function currency(string $code): string
    {
        $formatter = new NumberFormatter('en_'.strtoupper($code), NumberFormatter::CURRENCY);
        $currency = (string) $formatter->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        return Currencies::exists($currency) ? $currency : 'USD';
    }

    /**
     * Regional format (BCP 47) for dates, times and numbers, e.g. "en-CA".
     */
    public static function locale(string $code): string
    {
        return self::preset($code)['locale'] ?? 'en-'.strtoupper($code);
    }

    public static function timezone(string $code): string
    {
        $code = strtoupper($code);
        $preset = self::preset($code)['timezone'] ?? null;

        if ($preset !== null) {
            return $preset;
        }

        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code);

        return $zones[0] ?? (string) config('countries.fallback.timezone', 'UTC');
    }

    /**
     * @return array{region: string, postal: string, postal_pattern: string|null, order: string}
     */
    public static function addressFormat(?string $code): array
    {
        $fallback = (array) config('countries.fallback.address');

        return [...$fallback, ...(self::preset((string) $code)['address'] ?? [])];
    }

    /**
     * Everything a form needs to lay out an address for the country.
     *
     * @return array{region_label: string, postal_label: string, order: string}
     */
    public static function addressLabels(?string $code): array
    {
        $format = self::addressFormat($code);

        return [
            'region_label' => __("properties.regions.{$format['region']}"),
            'postal_label' => __("properties.postals.{$format['postal']}"),
            'order' => $format['order'],
        ];
    }

    /**
     * Regional formats offered in settings: English with the region of each country.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function localeOptions(): array
    {
        return array_map(fn (array $country) => [
            'value' => self::locale($country['value']),
            'label' => Locale::getDisplayName(self::locale($country['value']), 'en') ?: self::locale($country['value']),
        ], self::options());
    }

    /**
     * @return array<string, mixed>
     */
    private static function preset(string $code): array
    {
        return (array) config('countries.presets.'.strtoupper($code), []);
    }
}
