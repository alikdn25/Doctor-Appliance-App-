<?php

namespace App\Support;

/**
 * Phone number normalization for search and duplicate detection.
 * North American numbers become E.164 (+16045551234); other numbers keep their digits.
 */
class PhoneNumber
{
    public static function normalize(?string $number): string
    {
        $number = trim((string) $number);
        $digits = self::digits($number);

        if (strlen($digits) === 10 && ! str_starts_with($number, '+')) {
            return "+1{$digits}";
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return "+{$digits}";
        }

        return $digits === '' ? '' : "+{$digits}";
    }

    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }
}
