import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * Formats ISO timestamps in the current company's timezone (not the device's).
 */
export function useCompanyTime() {
    const { auth } = usePage().props;
    const timeZone = auth.company?.timezone ?? 'UTC';
    // The company's regional format decides the order and style (en-US: "Oct 6, 2026, 9:00 AM";
    // en-GB: "6 Oct 2026, 09:00").
    const locale = auth.company?.locale ?? 'en-US';

    return useMemo(() => {
        const make = (options: Intl.DateTimeFormatOptions) =>
            new Intl.DateTimeFormat(locale, { timeZone, ...options });

        const dayFormat = make({
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
        const timeFormat = make({ hour: 'numeric', minute: '2-digit' });
        const fullDayFormat = make({
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
        const dateTimeFormat = make({
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
        const dateFormat = make({
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
        // Plain dates (YYYY-MM-DD) have no time zone: format them as they are.
        const plainDateFormat = new Intl.DateTimeFormat(locale, {
            timeZone: 'UTC',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

        return {
            day: (iso: string) => dayFormat.format(new Date(iso)),
            /** "Thu, Oct 2, 2026" */
            fullDay: (iso: string) => fullDayFormat.format(new Date(iso)),
            time: (iso: string) => timeFormat.format(new Date(iso)),
            date: (iso: string) => dateFormat.format(new Date(iso)),
            dateTime: (iso: string) => dateTimeFormat.format(new Date(iso)),
            /** "2026-10-06" → "Oct 6, 2026" */
            dateOnly: (ymd: string) =>
                plainDateFormat.format(new Date(`${ymd}T00:00:00Z`)),
            /** "Tue, Oct 6 · 9:00 a.m. – 11:00 a.m." */
            window: (start: string, end: string) =>
                `${dayFormat.format(new Date(start))} · ${timeFormat.format(new Date(start))} – ${timeFormat.format(new Date(end))}`,
            /** "9:00 a.m. – 11:00 a.m." */
            timeRange: (start: string, end: string) =>
                `${timeFormat.format(new Date(start))} – ${timeFormat.format(new Date(end))}`,
        };
    }, [timeZone, locale]);
}

/** Minutes as "45 min" or "1 h 20 min". */
export function formatMinutes(
    minutes: number,
    t: (key: string, replacements?: Record<string, number>) => string,
): string {
    if (minutes < 60) {
        return t('jobs.minutes', { count: minutes });
    }

    return t('jobs.hours_minutes', {
        hours: Math.floor(minutes / 60),
        minutes: minutes % 60,
    });
}
