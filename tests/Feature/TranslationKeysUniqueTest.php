<?php

use Symfony\Component\Finder\Finder;

/*
 * A key written twice in one array of a translation file silently replaces the first group (jobs.details.created
 * showed as a raw key on the job page). Every array in every lang file has each key once.
 */

test('no translation file repeats a key in the same array', function () {
    $duplicates = [];

    foreach (Finder::create()->files()->in(lang_path())->name('*.php') as $file) {
        $stack = [[]];
        $pending = null;

        foreach (PhpToken::tokenize($file->getContents()) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }

            if ($token->text === '[') {
                $stack[] = [];
            } elseif ($token->text === ']') {
                array_pop($stack);
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $pending = $token->text;

                continue;
            } elseif ($token->is(T_DOUBLE_ARROW) && $pending !== null) {
                $key = $pending;
                $level = array_key_last($stack);
                if (isset($stack[$level][$key])) {
                    $duplicates[] = "{$file->getRelativePathname()}: {$key} (line {$token->line})";
                }
                $stack[$level][$key] = true;
            }

            $pending = null;
        }
    }

    expect($duplicates)->toBe([]);
});
