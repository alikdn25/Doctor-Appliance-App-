import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { cn } from '@/lib/utils';

export type TimeWindow = { start: string; end: string };

/**
 * Arrival windows as chips ("9–11 a.m.") in the company's time format. Windows that already started are left out
 * when the day is today. An optional first chip (e.g. "Now") comes before them.
 */
export function TimeWindowChips({
    windows,
    value,
    onChange,
    nowTime,
    leading,
    label,
    className,
}: {
    windows: TimeWindow[];
    /** The chosen window's start ("09:00") or the leading chip's value. */
    value: string | null;
    onChange: (start: string) => void;
    /** "HH:MM" now in the company's time zone when the day is today; earlier windows are hidden. */
    nowTime?: string | null;
    leading?: { value: string; label: string };
    label: string;
    className?: string;
}) {
    const locale = usePage().props.auth.company?.locale ?? 'en-US';
    const shown = useMemo(() => {
        const hours = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit', timeZone: 'UTC' });
        const at = (hhmm: string) => new Date(`1970-01-01T${hhmm}:00Z`);

        return windows
            .filter((w) => !nowTime || w.start > nowTime)
            .map((w) => ({ ...w, label: hours.formatRange(at(w.start), at(w.end)) }));
    }, [windows, nowTime, locale]);

    const chips = [...(leading ? [{ start: leading.value, label: leading.label }] : []), ...shown];

    return (
        <div
            role="radiogroup"
            aria-label={label}
            data-slot="time-window-chips"
            className={cn('flex flex-wrap gap-2', className)}
        >
            {chips.map((chip) => (
                <button
                    key={chip.start}
                    type="button"
                    role="radio"
                    aria-checked={chip.start === value}
                    onClick={() => onChange(chip.start)}
                    className="da-chip da-press inline-flex h-11 items-center rounded-2xl px-4 text-sm font-semibold whitespace-nowrap"
                >
                    {chip.label}
                </button>
            ))}
        </div>
    );
}
