<?php

namespace App\Support;

use Normalizer;

/** A decorative icon suggestion, never a recorded claim about the person's gender. */
class NameAvatar
{
    public const STYLES = ['auto', 'neutral', 'man', 'woman'];

    private static ?array $names = null;

    public static function suggest(?string $firstName): string
    {
        $name = Normalizer::normalize(trim((string) $firstName), Normalizer::FORM_KD);
        $name = mb_strtolower(preg_replace('/\p{Mn}/u', '', $name ?: '') ?? '');
        if (! preg_match("/^[a-z][a-z .'-]{1,98}$/", $name)) {
            return 'neutral';
        }
        self::$names ??= json_decode(file_get_contents(resource_path('data/name-icons.json')), true, flags: JSON_THROW_ON_ERROR)['names'];

        return self::$names[$name] ?? 'neutral';
    }
}
