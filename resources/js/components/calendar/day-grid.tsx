import { AlertTriangle } from 'lucide-react';
import type { DragEvent, TouchEvent } from 'react';
import type { CalendarVisit, Lane } from '@/components/calendar/types';
import { inLane } from '@/components/calendar/types';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Pixels per hour on the day grid (15 minutes = 14 px). */
export const HOUR_PX = 56;

type Props = {
    date: string;
    lanes: Lane[];
    visits: CalendarVisit[];
    hours: { start: number; end: number };
    travelBuffer: number;
    dragging: boolean;
    onVisitDragStart: (
        e: DragEvent,
        visit: CalendarVisit,
        lane: Lane,
        grabOffset: number,
    ) => void;
    onDragEnd: () => void;
    /** Long-press drag on phones (see the calendar page). */
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
 * Overlapping visits in one lane are placed side by side.
 */
function columns(visits: CalendarVisit[]) {
    const ends: number[] = [];
    const placed = visits.map((visit) => {
        let column = ends.findIndex((end) => end <= visit.start_minutes);

        if (column === -1) {
            column = ends.length;
            ends.push(0);
        }

        ends[column] = Math.max(
            visit.end_minutes,
            visit.on_site_minutes + visit.start_minutes,
        );

        return { visit, column };
    });

    return { placed, count: Math.max(1, ends.length) };
}

export function DayGrid({
    date,
    lanes,
    visits,
    hours,
    travelBuffer,
    dragging,
    onVisitDragStart,
    onDragEnd,
    onVisitTouchStart,
    onDrop,
    onOpen,
}: Props) {
    const t = useTrans();
    const height = (hours.end - hours.start) * HOUR_PX;
    const y = (minutes: number) =>
        ((minutes - hours.start * 60) / 60) * HOUR_PX;
    const hourList = Array.from(
        { length: hours.end - hours.start },
        (_, i) => hours.start + i,
    );

    const dropAt = (e: DragEvent<HTMLDivElement>, lane: Lane) => {
        e.preventDefault();
        const rect = e.currentTarget.getBoundingClientRect();
        const minutes =
            hours.start * 60 + ((e.clientY - rect.top) / HOUR_PX) * 60;
        onDrop(lane, date, minutes);
    };

    return (
        <div className="overflow-x-auto rounded-lg border" data-drag-scroll>
            <div className="flex min-w-max">
                <div className="w-12 shrink-0 border-r">
                    <div className="h-10 border-b" />
                    <div className="relative" style={{ height }}>
                        {hourList.map((h) => (
                            <span
                                key={h}
                                className="absolute right-1 -translate-y-1/2 text-[11px] text-muted-foreground"
                                style={{ top: y(h * 60) }}
                            >
                                {h === hours.start
                                    ? ''
                                    : `${((h + 11) % 12) + 1}${h < 12 ? 'a' : 'p'}`}
                            </span>
                        ))}
                    </div>
                </div>

                {lanes.map((lane) => {
                    const laneVisits = visits.filter(
                        (v) => v.date === date && inLane(v, lane),
                    );
                    const { placed, count } = columns(laneVisits);

                    return (
                        <div
                            key={lane.id ?? 'unassigned'}
                            className="w-44 shrink-0 border-r last:border-r-0 sm:w-52"
                        >
                            <div
                                className={cn(
                                    'flex h-10 items-center border-b px-2 text-sm font-medium',
                                    lane.id === null &&
                                        'text-muted-foreground italic',
                                )}
                                title={
                                    lane.inactive
                                        ? t('calendar.inactive')
                                        : undefined
                                }
                            >
                                <span className="truncate">
                                    {lane.name ?? t('calendar.unassigned')}
                                    {lane.inactive && ' *'}
                                </span>
                            </div>
                            <div
                                className={cn(
                                    'relative',
                                    dragging && 'bg-muted/30',
                                )}
                                style={{ height }}
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={(e) => dropAt(e, lane)}
                                data-drop-lane={lane.id ?? ''}
                                data-drop-date={date}
                                data-drop-mode="time"
                                data-hour-start={hours.start}
                            >
                                {hourList.map((h) => (
                                    <div
                                        key={h}
                                        className="pointer-events-none absolute inset-x-0 border-t border-dashed border-border/70"
                                        style={{ top: y(h * 60) }}
                                    />
                                ))}

                                {placed.map(({ visit, column }) => {
                                    const top = y(visit.start_minutes);
                                    const windowPx = Math.max(
                                        ((visit.end_minutes -
                                            visit.start_minutes) /
                                            60) *
                                            HOUR_PX,
                                        24,
                                    );
                                    const onSitePx = Math.max(
                                        (visit.on_site_minutes / 60) * HOUR_PX,
                                        windowPx,
                                    );
                                    const bufferPx =
                                        (travelBuffer / 60) * HOUR_PX;
                                    const width = 100 / count;

                                    return (
                                        <div
                                            key={visit.id}
                                            className="absolute px-0.5"
                                            style={{
                                                top,
                                                left: `${column * width}%`,
                                                width: `${width}%`,
                                            }}
                                        >
                                            <button
                                                type="button"
                                                draggable={visit.movable}
                                                onDragStart={(e) => {
                                                    const rect =
                                                        e.currentTarget.getBoundingClientRect();
                                                    onVisitDragStart(
                                                        e,
                                                        visit,
                                                        lane,
                                                        ((e.clientY -
                                                            rect.top) /
                                                            HOUR_PX) *
                                                            60,
                                                    );
                                                }}
                                                onDragEnd={onDragEnd}
                                                onTouchStart={(e) => {
                                                    const rect =
                                                        e.currentTarget.getBoundingClientRect();
                                                    onVisitTouchStart(
                                                        e,
                                                        visit,
                                                        lane,
                                                        ((e.touches[0].clientY -
                                                            rect.top) /
                                                            HOUR_PX) *
                                                            60,
                                                    );
                                                }}
                                                onContextMenu={(e) =>
                                                    e.preventDefault()
                                                }
                                                onClick={() => onOpen(visit)}
                                                className={cn(
                                                    'flex w-full flex-col justify-start overflow-hidden rounded-md border bg-card px-1.5 py-1 text-left text-xs shadow-sm select-none [-webkit-touch-callout:none]',
                                                    visit.movable
                                                        ? 'cursor-grab border-l-4 border-l-primary active:cursor-grabbing'
                                                        : 'border-l-4 border-l-muted-foreground/40 opacity-80',
                                                    visit.conflict &&
                                                        'border-destructive ring-1 ring-destructive',
                                                )}
                                                style={{ height: windowPx }}
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
                                                    <span className="truncate">
                                                        {visit.start_time}–
                                                        {visit.end_time}{' '}
                                                        {visit.job.customer}
                                                    </span>
                                                </span>
                                                <span className="block truncate text-muted-foreground">
                                                    #{visit.job.number} ·{' '}
                                                    {visit.job.appliances.join(
                                                        ', ',
                                                    ) ||
                                                        visit.job
                                                            .job_type_label}
                                                </span>
                                                <span className="block truncate text-muted-foreground">
                                                    {visit.job.address}
                                                </span>
                                            </button>
                                            {onSitePx > windowPx && (
                                                <div
                                                    className="rounded-b-md bg-primary/10"
                                                    style={{
                                                        height:
                                                            onSitePx - windowPx,
                                                    }}
                                                />
                                            )}
                                            {bufferPx > 0 && (
                                                <div
                                                    className="pointer-events-none rounded-b-md opacity-60"
                                                    title={t(
                                                        'calendar.travel_buffer',
                                                        {
                                                            minutes:
                                                                travelBuffer,
                                                        },
                                                    )}
                                                    style={{
                                                        height: bufferPx,
                                                        backgroundImage:
                                                            'repeating-linear-gradient(135deg, var(--muted-foreground) 0 1px, transparent 1px 6px)',
                                                    }}
                                                />
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
