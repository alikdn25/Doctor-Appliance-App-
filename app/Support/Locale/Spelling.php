<?php

namespace App\Support\Locale;

/**
 * Which English spelling the UI uses for a company's regional format: American ("Labor", "Check") for en-US,
 * Commonwealth ("Labour", "Cheque") for en-CA, en-GB, en-AU and the rest. The Commonwealth words live in
 * lang/en_GB and fall back to lang/en for everything else.
 */
class Spelling
{
    /** Regional formats that use American spelling. */
    private const AMERICAN = ['US', 'PH', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM'];

    public const COMMONWEALTH_LOCALE = 'en_GB';

    /**
     * The application (translation) locale for a company's regional format, e.g. "en-CA" → "en_GB", "en-US" → "en".
     */
    public static function appLocale(?string $regionalFormat): string
    {
        // The fallback, not app.locale: setLocale() rewrites app.locale.
        $default = (string) config('app.fallback_locale', 'en');

        if ($regionalFormat === null || $regionalFormat === '') {
            return $default;
        }

        $region = strtoupper((string) (preg_split('/[-_]/', $regionalFormat)[1] ?? ''));

        return $region === '' || in_array($region, self::AMERICAN, true) ? $default : self::COMMONWEALTH_LOCALE;
    }
}
