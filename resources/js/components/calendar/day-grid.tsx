import { AlarmClock, AlertTriangle, ArrowRightLeft, Check } from 'lucide-react';
import type { DragEvent, TouchEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { statusLook } from '@/lib/status';
import type { CalendarVisit, Lane } from '@/components/calendar/types';
import { inLane } from '@/components/calendar/types';
import { useTrans } from '@/lib/i18n';
import { useLocale } from '@/lib/locale';
import { isFinishedJob } from '@/components/jobs/types';
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
    /** A tap on an empty part of a lane: book there (UX-REDESIGN §9.1). */
    onEmptySlot?: (lane: Lane, minutes: number) => void;
    /** Minutes since midnight now, when the day shown is today (the red "now" line). */
    nowMinutes?: number | null;
    /**
     * "Transfer": the lane whose jobs are being ticked (undefined = off), the ticked visits, and the handlers.
     * Without onTransfer the lanes have no Transfer button.
     */
    transferLane?: number | null;
    selected?: number[];
    onTransfer?: (lane: Lane) => void;
    onToggle?: (visit: CalendarVisit) => void;
};

/** Lanes are at least 160 px wide (UX-REDESIGN §9.2). */
const LANE_PX = 176;

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
    onEmptySlot,
    nowMinutes = null,
    transferLane,
    selected = [],
    onTransfer,
    onToggle,
}: Props) {
    const transferring = transferLane !== undefined;
    const t = useTrans();
    // "+2 →": how many lanes are still off to the right.
    const scroller = useRef<HTMLDivElement>(null);
    const [hidden, setHidden] = useState(0);
    useEffect(() => {
        const element = scroller.current;
        if (!element) return;
        const measure = () =>
            setHidden(
                Math.max(
                    0,
                    Math.floor(
                        (element.scrollWidth -
                            element.scrollLeft -
                            element.clientWidth +
                            8) /
                            LANE_PX,
                    ),
                ),
            );
        measure();
        element.addEventListener('scroll', measure, { passive: true });
        window.addEventListener('resize', measure);
        return () => {
            element.removeEventListener('scroll', measure);
            window.removeEventListener('resize', measure);
        };
    }, [lanes.length]);
    const locale = useLocale();
    const hourFormat = new Intl.DateTimeFormat(locale, {
        hour: 'numeric',
        timeZone: 'UTC',
    });
    // "9–11 a.m." from wall-clock times.
    const shortRange = (start: string, end: string) => {
        const at = (hhmm: string) => new Date(`2000-01-01T${hhmm}:00Z`);
        const exact = [start, end].some((v) => !v.endsWith(':00'));
        return new Intl.DateTimeFormat(locale, {
            hour: 'numeric',
            ...(exact ? { minute: '2-digit' } : {}),
            timeZone: 'UTC',
        }).formatRange(at(start), at(end));
    };
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

    const minutesAt = (e: { clientY: number }, element: HTMLElement) =>
        hours.start * 60 +
        ((e.clientY - element.getBoundingClientRect().top) / HOUR_PX) * 60;

    return (
        <div className="relative">
            {hidden > 0 && (
                <button
                    type="button"
                    data-test="lanes-more"
                    className="da-chip absolute top-1.5 right-1.5 z-20 rounded-full px-2.5 text-xs leading-7 font-semibold"
                    onClick={() =>
                        scroller.current?.scrollBy({
                            left: LANE_PX * 2,
                            behavior: 'smooth',
                        })
                    }
                >
                    +{hidden} →
                </button>
            )}
            <div
                ref={scroller}
                className="da-card overflow-x-auto"
                data-drag-scroll
            >
                <div className="flex min-w-max">
                    <div className="sticky left-0 z-10 w-12 shrink-0 border-r bg-background">
                        <div className="h-10 border-b" />
                        <div className="relative" style={{ height }}>
                            {hourList.map((h) => (
                                <span
                                    key={h}
                                    className={`absolute right-1 text-[11px] text-muted-foreground ${h === hours.start ? 'translate-y-0.5' : '-translate-y-1/2'}`}
                                    style={{ top: y(h * 60) }}
                                >
                                    {hourFormat.format(
                                        new Date(Date.UTC(2000, 0, 1, h)),
                                    )}
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
                                className="shrink-0 border-r last:border-r-0"
                                style={{ width: LANE_PX }}
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
                                    <span className="min-w-0 flex-1 truncate">
                                        {lane.name ?? t('calendar.unassigned')}
                                        {lane.inactive && ' *'}
                                    </span>
                                    {onTransfer &&
                                        !transferring &&
                                        laneVisits.some((v) => v.movable) && (
                                            <button
                                                type="button"
                                                className="da-chip da-press inline-flex h-8 shrink-0 items-center gap-1 rounded-full px-2.5 text-xs font-semibold not-italic"
                                                onClick={() => onTransfer(lane)}
                                                data-test="lane-transfer"
                                            >
                                                <ArrowRightLeft className="size-3.5" />
                                                {t('jobs.transfer.action')}
                                            </button>
                                        )}
                                </div>
                                <div
                                    className={cn(
                                        'relative',
                                        dragging && 'bg-muted/30',
                                    )}
                                    style={{ height }}
                                    onDragOver={(e) => e.preventDefault()}
                                    onDrop={(e) => dropAt(e, lane)}
                                    onClick={(e) => {
                                        // Only the empty lane itself, not a visit on it.
                                        if (
                                            onEmptySlot &&
                                            !dragging &&
                                            e.target === e.currentTarget
                                        ) {
                                            onEmptySlot(
                                                lane,
                                                minutesAt(e, e.currentTarget),
                                            );
                                        }
                                    }}
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
                                    {nowMinutes !== null &&
                                        nowMinutes >= hours.start * 60 &&
                                        nowMinutes <= hours.end * 60 && (
                                            <div
                                                data-test="now-line"
                                                className="pointer-events-none absolute inset-x-0 z-10 border-t-2 border-[var(--now-line)]"
                                                style={{ top: y(nowMinutes) }}
                                            />
                                        )}

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
                                            (visit.on_site_minutes / 60) *
                                                HOUR_PX,
                                            windowPx,
                                        );
                                        const bufferPx =
                                            (travelBuffer / 60) * HOUR_PX;
                                        const width = 100 / count;
                                        const ticking =
                                            transferring &&
                                            transferLane === lane.id &&
                                            visit.movable;
                                        const ticked =
                                            ticking &&
                                            selected.includes(visit.id);
                                        // Late: still scheduled after its window ended.
                                        const overdue =
                                            visit.status === 'scheduled' &&
                                            nowMinutes !== null &&
                                            visit.end_minutes < nowMinutes;

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
                                                    draggable={
                                                        visit.movable &&
                                                        !transferring
                                                    }
                                                    role={
                                                        ticking
                                                            ? 'checkbox'
                                                            : undefined
                                                    }
                                                    aria-checked={
                                                        ticking
                                                            ? ticked
                                                            : undefined
                                                    }
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
                                                        if (transferring)
                                                            return;
                                                        const rect =
                                                            e.currentTarget.getBoundingClientRect();
                                                        onVisitTouchStart(
                                                            e,
                                                            visit,
                                                            lane,
                                                            ((e.touches[0]
                                                                .clientY -
                                                                rect.top) /
                                                                HOUR_PX) *
                                                                60,
                                                        );
                                                    }}
                                                    onContextMenu={(e) =>
                                                        e.preventDefault()
                                                    }
                                                    onClick={() =>
                                                        ticking
                                                            ? onToggle?.(visit)
                                                            : onOpen(visit)
                                                    }
                                                    data-status={
                                                        visit.job.status
                                                    }
                                                    data-overdue={
                                                        overdue || undefined
                                                    }
                                                    className={cn(
                                                        'flex w-full flex-col justify-start overflow-hidden rounded-md border border-l-4 bg-card px-1.5 py-1 text-left text-xs shadow-sm select-none [-webkit-touch-callout:none]',
                                                        visit.movable
                                                            ? 'cursor-grab active:cursor-grabbing'
                                                            : 'opacity-80',
                                                        (visit.conflict ||
                                                            overdue) &&
                                                            'border-danger ring-1 ring-danger',
                                                        ticked &&
                                                            'ring-2 ring-link',
                                                        isFinishedJob(
                                                            visit.job.status,
                                                        ) &&
                                                            'opacity-50 grayscale',
                                                    )}
                                                    style={{
                                                        height: windowPx,
                                                        // The status colour as a stripe (UX-REDESIGN §4.3).
                                                        borderLeftColor: `var(--status-${statusLook(visit.job.status).tone}-to)`,
                                                    }}
                                                >
                                                    <span className="flex items-center gap-1 font-medium">
                                                        {ticking && (
                                                            <span
                                                                className={cn(
                                                                    'flex size-4 shrink-0 items-center justify-center rounded border',
                                                                    ticked &&
                                                                        'border-link bg-link text-white',
                                                                )}
                                                                data-test="transfer-tick"
                                                            >
                                                                {ticked && (
                                                                    <Check className="size-3" />
                                                                )}
                                                            </span>
                                                        )}
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
                                                        <span className="truncate">
                                                            {shortRange(
                                                                visit.start_time,
                                                                visit.end_time,
                                                            )}{' '}
                                                            ·{' '}
                                                            {visit.job.customer}
                                                        </span>
                                                    </span>
                                                    <span className="block truncate text-muted-foreground">
                                                        {visit.job.appliances.join(
                                                            ', ',
                                                        ) ||
                                                            visit.job
                                                                .job_type_label}{' '}
                                                        · #{visit.job.number}
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
                                                                onSitePx -
                                                                windowPx,
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
        </div>
    );
}
