<?php

namespace App\Support\Locale;

use Illuminate\Support\Facades\Cache;
use libphonenumber\PhoneNumberUtil;
use NumberFormatter;
use ResourceBundle;

/**
 * ISO 4217 currencies in current use (the currencies of all countries, from ICU).
 * Amounts are stored in the currency's minor units: cents for USD/CAD/EUR, whole yen for JPY,
 * thousandths for KWD. Columns keep their historical "cents" naming.
 */
class Currencies
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return Cache::driver('array')->rememberForever('currencies.codes', function () {
            $codes = [];

            foreach (PhoneNumberUtil::getInstance()->getSupportedRegions() as $region) {
                $formatter = new NumberFormatter('en_'.$region, NumberFormatter::CURRENCY);
                $code = (string) $formatter->getTextAttribute(NumberFormatter::CURRENCY_CODE);

                if (preg_match('/^[A-Z]{3}$/', $code) === 1 && $code !== 'XXX') {
                    $codes[$code] = true;
                }
            }

            $codes = array_keys($codes);
            sort($codes);

            return $codes;
        });
    }

    public static function exists(?string $code): bool
    {
        return $code !== null && in_array($code, self::codes(), true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $code) => ['value' => $code, 'label' => "{$code} — ".self::name($code)], self::codes());
    }

    public static function name(string $code): string
    {
        $bundle = ResourceBundle::create('en', 'ICUDATA-curr');
        $name = $bundle?->get('Currencies')?->get($code)?->get(1);

        return is_string($name) ? $name : $code;
    }

    /**
     * Digits after the decimal point: 2 for USD, 0 for JPY, 3 for KWD.
     */
    public static function decimals(string $code): int
    {
        $formatter = new NumberFormatter('en@currency='.$code, NumberFormatter::CURRENCY);

        return (int) $formatter->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS);
    }

    /**
     * Minor units in one major unit: 100 for USD, 1 for JPY.
     */
    public static function factor(string $code): int
    {
        return 10 ** self::decimals($code);
    }

    /**
     * "12.5" (major units, as typed) → 1250 minor units.
     */
    public static function toMinor(string|int|float $amount, string $code): int
    {
        return (int) round((float) $amount * self::factor($code));
    }
}
