import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { IconButton } from '@/components/ui/icon-button';
import { Input } from '@/components/ui/input';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { useTrans } from '@/lib/i18n';

export type Period = 'day' | 'week' | 'month' | 'year' | 'custom';

export const PERIODS: Period[] = ['day', 'week', 'month', 'year', 'custom'];

// Dates are plain "YYYY-MM-DD" strings handled in UTC so no time zone moves them.
const parse = (ymd: string) => {
    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d));
};
const ymd = (date: Date) => date.toISOString().slice(0, 10);

/** The period of a kind that holds a day: [first day, last day]. */
export function periodOf(kind: Period, day: string): [string, string] {
    const date = parse(day);

    switch (kind) {
        case 'day':
            return [day, day];
        case 'week': {
            const monday = new Date(date);
            monday.setUTCDate(date.getUTCDate() - ((date.getUTCDay() + 6) % 7));
            const sunday = new Date(monday);
            sunday.setUTCDate(monday.getUTCDate() + 6);

            return [ymd(monday), ymd(sunday)];
        }
        case 'month':
            return [
                ymd(
                    new Date(
                        Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1),
                    ),
                ),
                ymd(
                    new Date(
                        Date.UTC(
                            date.getUTCFullYear(),
                            date.getUTCMonth() + 1,
                            0,
                        ),
                    ),
                ),
            ];
        case 'year':
            return [`${day.slice(0, 4)}-01-01`, `${day.slice(0, 4)}-12-31`];
        default:
            return [day, day];
    }
}

/** Which kind a from–to is: a whole day, week, month or year (one still running ends today), else "custom". */
export function kindOf(from: string, to: string, today: string): Period {
    for (const kind of ['day', 'week', 'month', 'year'] as const) {
        const [start, end] = periodOf(kind, from);

        if (start === from && (to === end || (to === today && today < end))) {
            return kind;
        }
    }

    return 'custom';
}

function shift(kind: Period, from: string, by: number): [string, string] {
    const date = parse(from);

    if (kind === 'day') {
        date.setUTCDate(date.getUTCDate() + by);
    } else if (kind === 'week') {
        date.setUTCDate(date.getUTCDate() + 7 * by);
    } else if (kind === 'month') {
        date.setUTCMonth(date.getUTCMonth() + by, 1);
    } else {
        date.setUTCFullYear(date.getUTCFullYear() + by, 0, 1);
    }

    return periodOf(kind, ymd(date));
}

/** "Oct 1 – 9" (the year only when it is not this year). */
export function formatRange(from: string, to: string, today: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        month: 'short',
        day: 'numeric',
        year: from.slice(0, 4) === today.slice(0, 4) ? undefined : 'numeric',
        timeZone: 'UTC',
    }).formatRange(parse(from), parse(to));
}

/**
 * The period in one tap (UX-REDESIGN §11.1): Day · Week · Month · Year · Custom; ‹ › moves it and it applies at
 * once; From / To only under Custom. The kind is kept in the URL as ?period=, since Oct 1–9 could be a month still
 * running or a custom range.
 */
export function PeriodPicker({
    from,
    to,
    today,
    onChange,
    kinds = PERIODS,
}: {
    from: string;
    to: string;
    today: string;
    onChange: (from: string, to: string, kind: Period) => void;
    kinds?: Period[];
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const pageUrl = usePage().url;
    const asked = new URLSearchParams(pageUrl.split('?')[1] ?? '').get('period');
    const kind: Period =
        asked && (kinds as string[]).includes(asked)
            ? (asked as Period)
            : kindOf(from, to, today);
    const range = formatRange(from, to, today, auth.company?.locale ?? 'en-US');
    const clip = (start: string, end: string, next: Period) =>
        onChange(start, end > today ? today : end, next);

    return (
        <div className="space-y-3">
            <SegmentedControl
                label={t('reports.profit_summary.period')}
                className="w-full"
                value={kinds.includes(kind) ? kind : 'custom'}
                onChange={(next) => {
                    if (next === 'custom') {
                        onChange(from, to, 'custom');

                        return;
                    }

                    const [start, end] = periodOf(next, today);
                    clip(start, end, next);
                }}
                options={kinds.map((value) => ({
                    value,
                    label: t(`reports.profit_summary.periods.${value}`),
                }))}
            />
            {kind === 'custom' ? (
                <div className="grid grid-cols-2 gap-2">
                    <label className="text-sm">
                        {t('reports.from')}
                        <Input
                            type="date"
                            value={from}
                            onChange={(e) =>
                                e.target.value &&
                                onChange(
                                    e.target.value,
                                    to < e.target.value ? e.target.value : to,
                                    'custom',
                                )
                            }
                        />
                    </label>
                    <label className="text-sm">
                        {t('reports.to')}
                        <Input
                            type="date"
                            value={to}
                            min={from}
                            onChange={(e) =>
                                e.target.value &&
                                onChange(from, e.target.value, 'custom')
                            }
                        />
                    </label>
                </div>
            ) : (
                <div className="flex items-center gap-2">
                    <IconButton
                        label={t('calendar.previous')}
                        onClick={() => {
                            const [start, end] = shift(kind, from, -1);
                            clip(start, end, kind);
                        }}
                    >
                        <ChevronLeft />
                    </IconButton>
                    <span
                        className="flex-1 text-center font-semibold"
                        data-test="report-range"
                    >
                        {range}
                    </span>
                    <IconButton
                        label={t('calendar.next')}
                        disabled={to >= today}
                        onClick={() => {
                            const [start, end] = shift(kind, from, 1);
                            clip(start, end, kind);
                        }}
                    >
                        <ChevronRight />
                    </IconButton>
                </div>
            )}
        </div>
    );
}
