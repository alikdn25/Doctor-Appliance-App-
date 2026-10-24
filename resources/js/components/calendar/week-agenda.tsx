import { MoreHorizontal } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { CalendarVisit } from '@/components/calendar/types';
import { dayLabel } from '@/components/calendar/types';
import { StatusBadge } from '@/components/jobs/status-badge';
import { isFinishedJob } from '@/components/jobs/types';
import { IconButton } from '@/components/ui/icon-button';
import { useClock } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { useLocale } from '@/lib/locale';
import { statusLook } from '@/lib/status';
import { cn } from '@/lib/utils';
import { isDraftJob } from '@/lib/status';

/**
 * Week on a phone (UX-REDESIGN §9.3): an agenda, one section per day ("Fri, Oct 9 · 3 jobs") with its jobs; it
 * opens scrolled to today. A job moves with "⋯ → Reschedule" (date, window, person) instead of dragging.
 */
export function WeekAgenda({
    days,
    today,
    visits,
    onOpen,
    onReschedule,
}: {
    days: string[];
    today: string;
    visits: CalendarVisit[];
    onOpen: (visit: CalendarVisit) => void;
    onReschedule: (visit: CalendarVisit) => void;
}) {
    const t = useTrans();
    const clock = useClock();
    const locale = useLocale();
    const todayRef = useRef<HTMLElement>(null);

    useEffect(() => {
        todayRef.current?.scrollIntoView({ block: 'start' });
    }, [days.join()]);

    return (
        <div className="space-y-4" data-test="week-agenda">
            {days.map((day) => {
                const dayVisits = visits
                    .filter((v) => v.date === day)
                    .sort((a, b) => a.start_minutes - b.start_minutes);

                return (
                    <section
                        key={day}
                        ref={day === today ? todayRef : undefined}
                        className="scroll-mt-24 space-y-2"
                    >
                        <h3
                            className={cn(
                                'text-sm font-semibold',
                                day === today && 'text-link',
                            )}
                        >
                            {dayLabel(day, locale)} ·{' '}
                            {t('calendar.jobs_count', {
                                count: dayVisits.length,
                            })}
                        </h3>
                        {dayVisits.length > 0 && (
                            <ul className="da-card divide-y overflow-hidden">
                                {dayVisits.map((visit) => (
                                    <li
                                        key={visit.id}
                                        className={cn(
                                            'flex items-center gap-2 border-l-4 pr-1',
                                            isDraftJob(visit.job.status) &&
                                                'bg-[var(--status-booking-draft-from)]',
                                            isFinishedJob(visit.job.status) &&
                                                'opacity-60 grayscale',
                                        )}
                                        style={{
                                            borderLeftColor: `var(--status-${statusLook(visit.job.status).tone}-to)`,
                                        }}
                                    >
                                        <button
                                            type="button"
                                            className="min-w-0 flex-1 px-3 py-2.5 text-left"
                                            onClick={() => onOpen(visit)}
                                        >
                                            <span className="flex items-center gap-2">
                                                <span className="font-semibold whitespace-nowrap tabular-nums">
                                                    {clock.range(
                                                        visit.start_time,
                                                        visit.end_time,
                                                    )}
                                                </span>
                                                <StatusBadge
                                                    status={visit.job.status}
                                                    label={
                                                        visit.job.status_label
                                                    }
                                                />
                                            </span>
                                            <span className="block truncate text-sm">
                                                {visit.job.customer}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {[
                                                    visit.assignees
                                                        .map((a) => a.name)
                                                        .join(', ') ||
                                                        t(
                                                            'calendar.unassigned',
                                                        ),
                                                    visit.job.address,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </button>
                                        {visit.movable && (
                                            <IconButton
                                                variant="plain"
                                                label={t('calendar.reschedule')}
                                                onClick={() =>
                                                    onReschedule(visit)
                                                }
                                            >
                                                <MoreHorizontal />
                                            </IconButton>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                );
            })}
        </div>
    );
}
