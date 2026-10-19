import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const addDays = (ymd: string, days: number) => {
    const date = new Date(`${ymd}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
};

/**
 * Day picker as chips: Today, Tomorrow, then the next days ("Wed 16") in the company's regional format; one tap.
 * Dates are plain YYYY-MM-DD in the company's calendar.
 */
export function DateChips({
    today,
    value,
    onChange,
    days = 7,
    label,
    className,
    trailing,
}: {
    /** Today in the company's time zone, YYYY-MM-DD. */
    today: string;
    value: string;
    onChange: (ymd: string) => void;
    days?: number;
    label: string;
    className?: string;
    /** The last item of the row, e.g. "Pick date" (scrolls with the chips, never covers them). */
    trailing?: ReactNode;
}) {
    const t = useTrans();
    const locale = usePage().props.auth.company?.locale ?? 'en-US';
    const chips = useMemo(() => {
        const short = new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', timeZone: 'UTC' });

        return Array.from({ length: days }, (_, i) => {
            const ymd = addDays(today, i);
            const name = i === 0 ? t('ui.today') : i === 1 ? t('ui.tomorrow') : short.format(new Date(`${ymd}T00:00:00Z`));

            return { ymd, name };
        });
    }, [today, days, locale, t]);

    return (
        <div
            role="radiogroup"
            aria-label={label}
            data-slot="date-chips"
            className={cn('-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] md:mx-0 md:flex-wrap md:px-0', className)}
        >
            {chips.map((chip) => (
                <button
                    key={chip.ymd}
                    type="button"
                    role="radio"
                    aria-checked={chip.ymd === value}
                    onClick={() => onChange(chip.ymd)}
                    className="da-chip da-press inline-flex h-11 shrink-0 items-center rounded-2xl px-4 text-sm font-semibold whitespace-nowrap"
                >
                    {chip.name}
                </button>
            ))}
            {trailing}
        </div>
    );
}
