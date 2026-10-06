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

        const ymdFormat = make({
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        });

        return {
            /** True when the moment falls on today's date in the company time zone. */
            isToday: (iso: string) =>
                ymdFormat.format(new Date(iso)) ===
                ymdFormat.format(new Date()),
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

/** Minutes as "45 min", "1 h" or "1 h 20 min". */
export function formatMinutes(
    minutes: number,
    t: (key: string, replacements?: Record<string, number>) => string,
): string {
    if (minutes < 60) {
        return t('jobs.minutes', { count: minutes });
    }

    if (minutes % 60 === 0) {
        return t('jobs.hours', { hours: minutes / 60 });
    }

    return t('jobs.hours_minutes', {
        hours: Math.floor(minutes / 60),
        minutes: minutes % 60,
    });
}

/**
 * Wall-clock times ("13:00") in the company's regional format ("1:00 p.m." for en-CA, "13:00" for en-GB),
 * so the calendar reads the same as the rest of the app.
 */
export function useClock() {
    const { auth } = usePage().props;
    const locale = auth.company?.locale ?? 'en-US';

    return useMemo(() => {
        const format = new Intl.DateTimeFormat(locale, {
            hour: 'numeric',
            minute: '2-digit',
            timeZone: 'UTC',
        });
        const clock = (hhmm: string) => {
            const [hours, minutes] = hhmm.split(':').map(Number);

            return Number.isNaN(hours)
                ? hhmm
                : format.format(
                      new Date(Date.UTC(2000, 0, 1, hours, minutes || 0)),
                  );
        };

        return {
            clock,
            range: (start: string, end: string) =>
                `${clock(start)} – ${clock(end)}`,
        };
    }, [locale]);
}
