<?php

namespace App\Support\Billing;

/**
 * Cleans an amount typed by a person before validation: "$15.5", "CA$ 1,250.00", "€ 15,50" → "15.5", "1250.00",
 * "15.50". Currency signs, letters and spaces are dropped; a lone comma followed by 1–2 digits is read as the
 * decimal mark, other commas as thousands separators.
 */
class MoneyInput
{
    public static function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/[^\d.,\-]/u', '', $value) ?? '';

        if ($clean === '') {
            return trim($value) === '' ? $value : trim($value);
        }

        if (! str_contains($clean, '.') && preg_match('/^-?\d+,\d{1,2}$/', $clean)) {
            return str_replace(',', '.', $clean);
        }

        return str_replace(',', '', $clean);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys  dot paths; "*" matches every item of a list
     * @return array<string, mixed>
     */
    public static function cleanPaths(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            $data = self::cleanPath($data, explode('.', $key));
        }

        return $data;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function cleanPath(mixed $data, array $segments): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            foreach ($data as $index => $item) {
                $data[$index] = $segments === [] ? self::clean($item) : self::cleanPath($item, $segments);
            }

            return $data;
        }

        if (! array_key_exists($segment, $data)) {
            return $data;
        }

        $data[$segment] = $segments === [] ? self::clean($data[$segment]) : self::cleanPath($data[$segment], $segments);

        return $data;
    }
}
