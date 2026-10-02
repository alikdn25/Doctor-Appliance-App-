import { usePage } from '@inertiajs/react';

/** The current company's regional format (BCP 47), e.g. "en-US", "en-CA", "en-GB". */
export function useLocale(): string {
    const { auth } = usePage().props;

    return auth.company?.locale ?? 'en-US';
}
