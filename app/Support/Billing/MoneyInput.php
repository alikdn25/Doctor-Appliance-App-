<?php

namespace App\Support\Billing;

/**
 * Cleans an amount typed by a person before validation: "$15.5", "CA$ 1,250.00", "€ 15,50", "1.250,00" → "15.5",
 * "1250.00", "15.50", "1250.00". Currency signs, letters and spaces are dropped; a lone comma followed by 1–2 digits
 * is the decimal mark (phone keyboards often show a comma), commas between groups of three digits separate
 * thousands. Anything else ("150.5.5", "15,5,5") is returned as typed so that validation rejects it.
 * Mirrors parseNumber() in resources/js/components/billing/money.ts.
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

        return self::normalize($clean) ?? trim($value);
    }

    private static function normalize(string $clean): ?string
    {
        if (! preg_match('/^(-?)([\d.,]+)$/', $clean, $match)) {
            return null;
        }

        [, $sign, $body] = $match;
        $lastDot = strrpos($body, '.');
        $lastComma = strrpos($body, ',');
        $digits = null;

        if ($lastDot === false && $lastComma === false) {
            $digits = $body;
        } elseif ($lastDot === false || $lastComma === false) {
            $mark = $lastComma === false ? '.' : ',';
            $parts = explode($mark, $body);

            if (count($parts) === 2 && preg_match('/^\d*$/', $parts[0])) {
                if (preg_match('/^\d{1,2}$/', $parts[1]) || ($mark === '.' && preg_match('/^\d+$/', $parts[1]))) {
                    $digits = ($parts[0] === '' ? '0' : $parts[0]).'.'.$parts[1];
                } elseif ($mark === ',' && preg_match('/^\d{3}$/', $parts[1])) {
                    $digits = implode('', $parts);
                }
            } elseif ($mark === ',' && self::grouped($parts)) {
                $digits = implode('', $parts);
            }
        } else {
            // Both marks: the last one is the decimal mark, the other groups thousands.
            [$group, $decimal] = $lastDot > $lastComma ? [',', '.'] : ['.', ','];
            $pieces = explode($decimal, $body);

            if (count($pieces) === 2 && preg_match('/^\d+$/', $pieces[1]) && self::grouped($groups = explode($group, $pieces[0]))) {
                $digits = implode('', $groups).'.'.$pieces[1];
            }
        }

        return $digits !== null && preg_match('/^\d+(\.\d+)?$/', $digits) ? $sign.$digits : null;
    }

    /**
     * "1", "500", "000" → thousands groups: 1–3 digits first, then exactly three.
     *
     * @param  list<string>  $groups
     */
    private static function grouped(array $groups): bool
    {
        if (! preg_match('/^\d{1,3}$/', $groups[0])) {
            return false;
        }

        foreach (array_slice($groups, 1) as $group) {
            if (! preg_match('/^\d{3}$/', $group)) {
                return false;
            }
        }

        return true;
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
