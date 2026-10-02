import { Link } from '@inertiajs/react';
import { AlarmClock, AlertTriangle } from 'lucide-react';
import type { DragEvent, TouchEvent } from 'react';
import type { CalendarVisit, Lane } from '@/components/calendar/types';
import { dayLabel, inLane } from '@/components/calendar/types';
import { useTrans } from '@/lib/i18n';
import { useLocale } from '@/lib/locale';
import { cn } from '@/lib/utils';
import { calendar } from '@/routes';

type Props = {
    days: string[];
    today: string;
    lanes: Lane[];
    visits: CalendarVisit[];
    travelBuffer: number;
    dragging: boolean;
    onVisitDragStart: (
        e: DragEvent,
        visit: CalendarVisit,
        lane: Lane,
        grabOffset: number,
    ) => void;
    onDragEnd: () => void;
    onVisitTouchStart: (
        e: TouchEvent<HTMLElement>,
        visit: CalendarVisit,
        lane: Lane,
        grabOffset: number,
    ) => void;
    onDrop: (lane: Lane, date: string, minutes: number | null) => void;
    onOpen: (visit: CalendarVisit) => void;
};

/**
 * Week: one row per person, one column per day. Dropping a visit on a cell keeps its time.
 */
export function WeekGrid({
    days,
    today,
    lanes,
    visits,
    travelBuffer,
    dragging,
    onVisitDragStart,
    onDragEnd,
    onVisitTouchStart,
    onDrop,
    onOpen,
}: Props) {
    const t = useTrans();
    const locale = useLocale();

    return (
        <div className="overflow-x-auto rounded-lg border" data-drag-scroll>
            <table className="w-full min-w-[64rem] table-fixed border-collapse text-xs">
                <thead>
                    <tr>
                        <th className="w-32 border-b p-2 text-left font-medium" />
                        {days.map((day) => (
                            <th
                                key={day}
                                className={cn(
                                    'border-b border-l p-2 text-left font-medium',
                                    day === today && 'bg-primary/5',
                                )}
                            >
                                <Link
                                    href={calendar({
                                        query: { view: 'day', date: day },
                                    })}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {dayLabel(day, locale)}
                                </Link>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {lanes.map((lane) => (
                        <tr key={lane.id ?? 'unassigned'}>
                            <th
                                className={cn(
                                    'border-b p-2 text-left align-top text-sm font-medium',
                                    lane.id === null &&
                                        'text-muted-foreground italic',
                                )}
                            >
                                <span className="block truncate">
                                    {lane.name ?? t('calendar.unassigned')}
                                    {lane.inactive && ' *'}
                                </span>
                            </th>
                            {days.map((day) => {
                                const cellVisits = visits.filter(
                                    (v) => v.date === day && inLane(v, lane),
                                );

                                return (
                                    <td
                                        key={day}
                                        className={cn(
                                            'h-20 border-b border-l p-1 align-top',
                                            day === today && 'bg-primary/5',
                                            dragging && 'bg-muted/30',
                                        )}
                                        data-drop-lane={lane.id ?? ''}
                                        data-drop-date={day}
                                        data-drop-mode="day"
                                        onDragOver={(e) => e.preventDefault()}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            onDrop(lane, day, null);
                                        }}
                                    >
                                        <div className="space-y-1">
                                            {cellVisits.map((visit) => (
                                                <button
                                                    key={visit.id}
                                                    type="button"
                                                    draggable={visit.movable}
                                                    onDragStart={(e) =>
                                                        onVisitDragStart(
                                                            e,
                                                            visit,
                                                            lane,
                                                            0,
                                                        )
                                                    }
                                                    onDragEnd={onDragEnd}
                                                    onTouchStart={(e) =>
                                                        onVisitTouchStart(
                                                            e,
                                                            visit,
                                                            lane,
                                                            0,
                                                        )
                                                    }
                                                    onContextMenu={(e) =>
                                                        e.preventDefault()
                                                    }
                                                    onClick={() =>
                                                        onOpen(visit)
                                                    }
                                                    className={cn(
                                                        'block w-full rounded border bg-card px-1.5 py-1 text-left shadow-sm select-none [-webkit-touch-callout:none]',
                                                        visit.movable
                                                            ? 'cursor-grab border-l-4 border-l-primary'
                                                            : 'border-l-4 border-l-muted-foreground/40 opacity-80',
                                                        visit.conflict &&
                                                            'border-destructive ring-1 ring-destructive',
                                                    )}
                                                >
                                                    <span className="flex items-center gap-1 font-medium">
                                                        {visit.conflict && (
                                                            <AlertTriangle
                                                                className="size-3 shrink-0 text-destructive"
                                                                aria-label={t(
                                                                    'calendar.conflict',
                                                                    {
                                                                        minutes:
                                                                            travelBuffer,
                                                                    },
                                                                )}
                                                            />
                                                        )}
                                                        {visit.strict_arrival && (
                                                            <AlarmClock
                                                                className="size-3 shrink-0 text-red-600"
                                                                aria-label={t(
                                                                    'jobs.strict.badge',
                                                                )}
                                                            />
                                                        )}
                                                        {visit.start_time}
                                                    </span>
                                                    <span className="block truncate">
                                                        {visit.job.customer}
                                                    </span>
                                                    <span className="block truncate text-muted-foreground">
                                                        #{visit.job.number}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
