import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

type Replacements = Record<string, string | number | null | undefined>;

type TranslationTree = { [key: string]: string | TranslationTree };

function lookup(tree: TranslationTree, key: string): string | undefined {
    let node: string | TranslationTree | undefined = tree;

    for (const part of key.split('.')) {
        if (node === undefined || typeof node === 'string') {
            return undefined;
        }

        node = node[part];
    }

    return typeof node === 'string' ? node : undefined;
}

/**
 * Translate a key from lang/{locale}/*.php, e.g. t('brands.title').
 * Placeholders use Laravel's ":name" syntax. Unknown keys return the key itself.
 */
export function translate(
    translations: TranslationTree,
    key: string,
    replacements: Replacements = {},
): string {
    let text = lookup(translations, key) ?? key;

    for (const [name, value] of Object.entries(replacements)) {
        text = text.replaceAll(`:${name}`, String(value ?? ''));
    }

    return text;
}

export function useTrans() {
    const { translations } = usePage().props;

    return useCallback(
        (key: string, replacements?: Replacements) =>
            translate(translations as TranslationTree, key, replacements),
        [translations],
    );
}
