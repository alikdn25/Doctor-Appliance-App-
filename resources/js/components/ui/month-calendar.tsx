import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const ymd = (date: Date) => date.toISOString().slice(0, 10);

/**
 * A month of days in the page flow (not a pop-up), so it never covers the day chips above it. Dates are plain
 * YYYY-MM-DD in the company's calendar; the week starts on the regional first day.
 */
export function MonthCalendar({
    value,
    min,
    onChange,
    label,
}: {
    value: string;
    min?: string;
    onChange: (ymd: string) => void;
    label: string;
}) {
    const t = useTrans();
    const locale = usePage().props.auth.company?.locale ?? 'en-US';
    const [month, setMonth] = useState(() => (value || min || ymd(new Date())).slice(0, 7));
    const firstDay = useMemo(() => {
        try {
            const info = (new Intl.Locale(locale) as Intl.Locale & { weekInfo?: { firstDay: number }; getWeekInfo?: () => { firstDay: number } });
            const week = info.getWeekInfo?.() ?? info.weekInfo;

            return (week?.firstDay ?? 7) % 7;
        } catch {
            return 0;
        }
    }, [locale]);

    const start = new Date(`${month}-01T00:00:00Z`);
    const lead = (start.getUTCDay() - firstDay + 7) % 7;
    const daysInMonth = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0)).getUTCDate();
    const cells = [
        ...Array.from({ length: lead }, () => null),
        ...Array.from({ length: daysInMonth }, (_, i) => ymd(new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth(), i + 1)))),
    ];
    const shift = (months: number) => {
        const next = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + months, 1));
        setMonth(ymd(next).slice(0, 7));
    };
    const title = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(start);
    const weekday = new Intl.DateTimeFormat(locale, { weekday: 'narrow', timeZone: 'UTC' });
    // 2023-01-01 was a Sunday.
    const weekdays = Array.from({ length: 7 }, (_, i) => weekday.format(new Date(Date.UTC(2023, 0, 1 + ((firstDay + i) % 7)))));
    const canGoBack = !min || `${month}-01` > min.slice(0, 7) + '-01';

    return (
        <div className="da-card p-3" data-test="month-calendar" data-no-swipe>
            <div className="mb-2 flex items-center justify-between">
                <button
                    type="button"
                    className="da-press flex size-11 items-center justify-center rounded-xl disabled:opacity-40"
                    aria-label={t('ui.previous_month')}
                    disabled={!canGoBack}
                    onClick={() => shift(-1)}
                >
                    <ChevronLeft className="size-5" />
                </button>
                <span className="text-sm font-semibold capitalize">{title}</span>
                <button
                    type="button"
                    className="da-press flex size-11 items-center justify-center rounded-xl"
                    aria-label={t('ui.next_month')}
                    onClick={() => shift(1)}
                >
                    <ChevronRight className="size-5" />
                </button>
            </div>
            <div role="radiogroup" aria-label={label} className="grid grid-cols-7 gap-1 text-center">
                {weekdays.map((name, i) => (
                    <span key={`w${i}`} aria-hidden="true" className="text-xs font-medium text-muted-foreground">
                        {name}
                    </span>
                ))}
                {cells.map((day, i) =>
                    day === null ? (
                        <span key={`e${i}`} />
                    ) : (
                        <button
                            key={day}
                            type="button"
                            role="radio"
                            aria-checked={day === value}
                            aria-label={new Intl.DateTimeFormat(locale, { dateStyle: 'full', timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`))}
                            disabled={!!min && day < min}
                            onClick={() => onChange(day)}
                            className={cn(
                                'da-press h-10 rounded-xl text-sm font-semibold disabled:opacity-30',
                                day === value && 'da-chip',
                            )}
                            data-state={day === value ? 'on' : 'off'}
                        >
                            {Number(day.slice(8))}
                        </button>
                    ),
                )}
            </div>
        </div>
    );
}
