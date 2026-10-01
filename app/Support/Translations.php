<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Loads UI translation groups (lang/{locale}/*.php) to share with the React frontend.
 * Laravel's own validation/pagination groups are not shared.
 */
class Translations
{
    private const EXCLUDED = ['validation', 'pagination', 'passwords'];

    /**
     * @return array<string, mixed>
     */
    public static function forLocale(string $locale): array
    {
        $fallback = config('app.fallback_locale');

        return array_replace_recursive(
            $locale !== $fallback ? self::load($fallback) : [],
            self::load($locale),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $locale): array
    {
        $path = lang_path($locale);

        if (! File::isDirectory($path)) {
            return [];
        }

        $groups = [];

        foreach (File::files($path) as $file) {
            $group = $file->getFilenameWithoutExtension();

            if ($file->getExtension() !== 'php' || in_array($group, self::EXCLUDED, true)) {
                continue;
            }

            $groups[$group] = require $file->getPathname();
        }

        return $groups;
    }
}
