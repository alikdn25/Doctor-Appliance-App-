import { usePage } from '@inertiajs/react';
import type { CountryCode } from 'libphonenumber-js/min';
import {
    getCountryCallingCode,
    isSupportedCountry,
    parsePhoneNumberFromString,
} from 'libphonenumber-js/min';
import { useCallback } from 'react';

/**
 * Phones are stored in E.164 (+16045550142). On screen they are shown in the national format of the company's
 * country ("(604) 555-0142"); numbers with another calling code in international format ("+44 20 7946 0958").
 * Links (tel:, sms:) keep using the E.164 value.
 */
export function formatPhone(
    value: string | null | undefined,
    country: string,
): string {
    if (!value) {
        return '';
    }

    const region = isSupportedCountry(country)
        ? (country as CountryCode)
        : undefined;
    const parsed = parsePhoneNumberFromString(value, region);

    if (!parsed) {
        return value;
    }

    return region && parsed.countryCallingCode === getCountryCallingCode(region)
        ? parsed.formatNational()
        : parsed.formatInternational();
}

/** Keeps only characters a phone number can contain: digits, +, spaces, dashes, dots and brackets. */
export function phoneCharacters(value: string): string {
    return value.replace(/[^\d+\-().\s]/g, '');
}

/** Whether the typed number can be a real phone number (read in the company's country when it has no +). */
export function isPossiblePhone(value: string, country: string): boolean {
    const region = isSupportedCountry(country)
        ? (country as CountryCode)
        : undefined;
    const parsed = parsePhoneNumberFromString(value, region);

    return !!parsed && parsed.isPossible();
}

/** Formatter bound to the current company's country. */
export function usePhone() {
    const { auth } = usePage().props;
    const country = auth.company?.country ?? 'US';

    return useCallback(
        (value: string | null | undefined) => formatPhone(value, country),
        [country],
    );
}
