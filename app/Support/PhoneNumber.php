<?php

namespace App\Support;

use App\Support\Tenancy\CurrentCompany;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\ValidationResult;

/**
 * Phone numbers are stored in E.164 (+16045551234, +442079460958). A number typed without a country code
 * is read as a number of the given country (default: the current company's country).
 * Numbers that cannot be parsed keep their digits with a leading "+".
 */
class PhoneNumber
{
    public static function normalize(?string $number, ?string $country = null): string
    {
        $number = trim((string) $number);

        if (self::digits($number) === '') {
            return '';
        }

        $parsed = self::parse($number, $country);

        if ($parsed !== null) {
            return PhoneNumberUtil::getInstance()->format($parsed, PhoneNumberFormat::E164);
        }

        return '+'.self::digits($number);
    }

    /**
     * Whether the number has a plausible length for its country (lenient: no check of real number ranges,
     * so test and new numbers are accepted).
     */
    public static function isPossible(?string $number, ?string $country = null): bool
    {
        return self::parse(trim((string) $number), $country) !== null;
    }

    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /**
     * Digits to look for in stored E.164 numbers when searching: a typed national number
     * ("020 7946 0958", "604-555") matches without its trunk prefix.
     */
    public static function searchDigits(string $term): string
    {
        $digits = self::digits($term);

        return str_starts_with(trim($term), '+') ? $digits : ltrim($digits, '0');
    }

    public static function defaultCountry(): string
    {
        return app(CurrentCompany::class)->get()?->country ?? 'US';
    }

    private static function parse(string $number, ?string $country): ?\libphonenumber\PhoneNumber
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($number, strtoupper($country ?? self::defaultCountry()));
        } catch (NumberParseException) {
            return null;
        }

        return $util->isPossibleNumberWithReason($parsed) === ValidationResult::IS_POSSIBLE ? $parsed : null;
    }
}
