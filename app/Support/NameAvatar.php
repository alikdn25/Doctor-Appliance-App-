<?php

namespace App\Support;

use Normalizer;

/** A decorative icon suggestion, never a recorded claim about the person's gender. */
class NameAvatar
{
    /** Choices people can save: a face, or automatic from the first name (initials when the name fits both). */
    public const STYLES = ['auto', 'man', 'woman'];

    /**
     * Latin spellings missing from the Faker-based dictionary: common transliterations of Ukrainian and
     * Russian names (e.g. Oleksandr, Olena). Used only when the dictionary has no entry.
     */
    private const EXTRA_MEN = [
        'oleksandr', 'olexandr', 'oleksander', 'oleksii', 'oleksiy', 'olexiy', 'alexei', 'alexey', 'aleksey',
        'andrii', 'andriy', 'andrey', 'dmytro', 'dmitriy', 'serhii', 'serhiy', 'sergiy', 'volodymyr', 'mykola',
        'mykhailo', 'mykhaylo', 'mikhail', 'yurii', 'yuriy', 'yury', 'vasily', 'taras', 'ihor', 'pavlo', 'maksym',
        'artem', 'artyom', 'vitalii', 'vitaliy', 'vitaly', 'yevhen', 'yevgeny', 'evgeny', 'evgeniy', 'anatolii',
        'anatoliy', 'anatoly', 'oleh', 'yaroslav', 'rostyslav', 'vadym', 'denys', 'kostiantyn', 'nazar', 'valerii',
        'valeriy', 'hennadii', 'gennady', 'ruslan', 'illia', 'ilya', 'mykyta', 'kyrylo', 'arsenii', 'timur',
    ];

    private const EXTRA_WOMEN = [
        'olena', 'iryna', 'tetiana', 'tetyana', 'nataliia', 'natalya', 'svitlana', 'liudmyla', 'lyudmila', 'halyna',
        'yuliia', 'yuliya', 'kateryna', 'yekaterina', 'mariia', 'anastasiia', 'viktoriia', 'olha', 'olga',
        'valentyna', 'larysa', 'nadiia', 'nadezhda', 'liubov', 'lyubov', 'zoia', 'zoya', 'khrystyna', 'dariia',
        'darya', 'sofiia', 'solomiia', 'oleksandra', 'vira', 'ganna', 'maryna', 'kseniia', 'lilia', 'liliia',
    ];

    private static ?array $names = null;

    public static function suggest(?string $firstName): string
    {
        $name = Normalizer::normalize(trim((string) $firstName), Normalizer::FORM_KD);
        $name = mb_strtolower(preg_replace('/\p{Mn}/u', '', $name ?: '') ?? '');
        if (! preg_match("/^[a-z][a-z .'-]{1,98}$/", $name)) {
            return 'neutral';
        }
        self::$names ??= json_decode(file_get_contents(resource_path('data/name-icons.json')), true, flags: JSON_THROW_ON_ERROR)['names'];

        // The whole name is sometimes typed into the first-name field ("Oleksandr Mykhailychenko"):
        // try the full value, then its first word.
        foreach (array_unique([$name, strtok($name, ' ')]) as $candidate) {
            $icon = self::$names[$candidate] ?? match (true) {
                in_array($candidate, self::EXTRA_MEN, true) => 'man',
                in_array($candidate, self::EXTRA_WOMEN, true) => 'woman',
                default => null,
            };

            if ($icon !== null) {
                return $icon;
            }
        }

        return 'neutral';
    }
}
