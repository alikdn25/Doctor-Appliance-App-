import type { DragEvent, TouchEvent } from 'react';
import { NoAddressBadge } from '@/components/jobs/no-address-badge';
import { StatusBadge } from '@/components/jobs/status-badge';
import type { UnscheduledJob } from '@/components/calendar/types';
import { dayLabel } from '@/components/calendar/types';
import { useTrans } from '@/lib/i18n';
import { useLocale } from '@/lib/locale';

/**
 * Jobs without a time (owner's task of November 25): a "No time" strip at the top of each shown day, above the
 * hours, and the "No date" jobs above today. Tapping a job opens it (to set a time there); dragging it onto the hours
 * books that time.
 */
export function NoTimeStrip({
    days,
    today,
    noTime,
    noDate,
    onOpen,
    onDragStart,
    onDragEnd,
    onTouchStart,
}: {
    days: string[];
    today: string;
    noTime: Record<string, UnscheduledJob[]>;
    noDate: UnscheduledJob[];
    onOpen: (job: UnscheduledJob) => void;
    onDragStart: (e: DragEvent, job: UnscheduledJob) => void;
    onDragEnd: () => void;
    onTouchStart: (e: TouchEvent<HTMLElement>, job: UnscheduledJob) => void;
}) {
    const t = useTrans();
    const locale = useLocale();
    const shown = days.filter(
        (day) =>
            (noTime[day]?.length ?? 0) > 0 ||
            (day === today && noDate.length > 0),
    );

    if (shown.length === 0) {
        return null;
    }

    const chip = (job: UnscheduledJob, label: string) => (
        <li key={job.id}>
            <button
                type="button"
                draggable
                onDragStart={(e) => onDragStart(e, job)}
                onDragEnd={onDragEnd}
                onTouchStart={(e) => onTouchStart(e, job)}
                onContextMenu={(e) => e.preventDefault()}
                onClick={() => onOpen(job)}
                className="da-raised block min-h-11 w-full cursor-grab rounded-xl border border-amber-300 bg-amber-50 p-2 text-left text-xs select-none [-webkit-touch-callout:none] hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950 dark:hover:bg-amber-900"
                data-test="no-time-job"
            >
                <span className="flex items-center justify-between gap-2">
                    <span className="min-w-0 truncate font-semibold">
                        #{job.number} {job.customer}
                    </span>
                    <span className="shrink-0 rounded-full bg-amber-200 px-2 py-0.5 text-[10px] font-semibold text-amber-900 dark:bg-amber-800 dark:text-amber-50">
                        {label}
                    </span>
                </span>
                <span className="block truncate text-muted-foreground">
                    {[job.job_type_label, job.appliances.join(', ')]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
                <span className="flex items-center justify-between gap-2">
                    <span className="min-w-0 truncate text-muted-foreground">
                        {job.address || <NoAddressBadge />}
                    </span>
                    <StatusBadge status={job.status} label={job.status_label} />
                </span>
            </button>
        </li>
    );

    return (
        <section
            className="da-card space-y-3 p-3"
            aria-label={t('calendar.no_time')}
            data-test="no-time-strip"
        >
            <p className="text-xs text-muted-foreground">
                {t('calendar.no_time_hint')}
            </p>
            {shown.map((day) => (
                <div key={day} className="space-y-2" data-day={day}>
                    {days.length > 1 && (
                        <h3 className="text-xs font-semibold text-muted-foreground">
                            {dayLabel(day, locale, {
                                weekday: 'short',
                                month: 'short',
                                day: 'numeric',
                            })}
                        </h3>
                    )}
                    <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {day === today &&
                            noDate.map((job) =>
                                chip(job, t('calendar.no_date')),
                            )}
                        {(noTime[day] ?? []).map((job) =>
                            chip(job, t('calendar.no_time')),
                        )}
                    </ul>
                </div>
            ))}
        </section>
    );
}
