import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    MapPin,
    Navigation,
    Pencil,
} from 'lucide-react';
import type { DragEvent, TouchEvent } from 'react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { DayMap } from '@/components/calendar/day-map';
import { DayGrid, HOUR_PX } from '@/components/calendar/day-grid';
import type {
    CalendarVisit,
    DragItem,
    Lane,
    UnscheduledJob,
} from '@/components/calendar/types';
import {
    dayLabel,
    inLane,
    routeUrl,
    toTime,
} from '@/components/calendar/types';
import { WeekGrid } from '@/components/calendar/week-grid';
import { StatusBadge } from '@/components/jobs/status-badge';
import type { Assignable } from '@/components/jobs/types';
import { VisitDialog } from '@/components/jobs/visit-dialog';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTouchDrag } from '@/hooks/use-touch-drag';
import { useTrans } from '@/lib/i18n';
import { useLocale } from '@/lib/locale';
import { cn } from '@/lib/utils';
import { calendar } from '@/routes';
import { show as showJob } from '@/routes/jobs';
import { move, store as storeVisit } from '@/routes/visits';

type Props = {
    view: 'day' | 'week' | 'map';
    date: string;
    today: string;
    days: string[];
    previous: string;
    next: string;
    hours: { start: number; end: number };
    travelBuffer: number;
    lanes: Lane[];
    visits: CalendarVisit[];
    unscheduled: UnscheduledJob[];
    assignableUsers: Assignable[];
};

/** New visits dropped on the calendar get a 2-hour arrival window and a 1-hour estimate. */
const NEW_WINDOW = 120;
const snap = (minutes: number) => Math.round(minutes / 15) * 15;

export default function CalendarPage({
    view,
    date,
    today,
    days,
    previous,
    next,
    hours,
    travelBuffer,
    lanes,
    visits,
    unscheduled,
    assignableUsers,
}: Props) {
    const t = useTrans();
    const locale = useLocale();
    const drag = useRef<DragItem | null>(null);
    const [dragging, setDragging] = useState(false);
    const [details, setDetails] = useState<CalendarVisit | null>(null);
    const [editing, setEditing] = useState<CalendarVisit | null>(null);
    const [scheduling, setScheduling] = useState<UnscheduledJob | null>(null);

    const go = (query: Record<string, string>) =>
        router.get(
            calendar().url,
            { view, date, ...query },
            {
                preserveScroll: true,
            },
        );

    const options = {
        preserveScroll: true,
        preserveState: true,
        onError: (errors: Record<string, string>) =>
            toast.error(Object.values(errors)[0]),
    };

    const startDrag = (e: DragEvent, item: DragItem) => {
        drag.current = item;
        setDragging(true);
        e.dataTransfer.effectAllowed = 'move';
        // Firefox needs data to start a drag.
        e.dataTransfer.setData('text/plain', String(item.kind));
    };

    const endDrag = () => {
        setDragging(false);
    };

    const drop = (lane: Lane, day: string, minutes: number | null) => {
        const item = drag.current;
        drag.current = null;
        setDragging(false);

        if (!item) {
            return;
        }

        if (item.kind === 'visit') {
            const { visit } = item;
            const start =
                minutes === null
                    ? visit.start_minutes
                    : snap(minutes - item.grabOffset);

            if (
                day === visit.date &&
                start === visit.start_minutes &&
                lane.id === item.fromLane
            ) {
                return;
            }

            router.put(
                move(visit.id).url,
                {
                    date: day,
                    start_time: toTime(start),
                    from_user_id: item.fromLane,
                    to_user_id: lane.id,
                },
                options,
            );

            return;
        }

        const start = Math.min(
            minutes === null ? hours.start * 60 : snap(minutes),
            24 * 60 - NEW_WINDOW,
        );

        router.post(
            storeVisit(item.job.id).url,
            {
                date: day,
                start_time: toTime(start),
                end_time: toTime(start + NEW_WINDOW),
                estimated_duration_minutes: 60,
                assignee_ids: lane.id === null ? [] : [lane.id],
                back: true,
            },
            options,
        );
    };

    // Phones: press and hold, then drag with the finger.
    const touch = useTouchDrag<DragItem>({
        hourPx: HOUR_PX,
        onStart: (item) => {
            drag.current = item;
            setDragging(true);
        },
        onDrop: (item, target) => {
            drag.current = item;
            drop(
                { id: target.lane, name: null, inactive: false },
                target.date,
                target.minutes,
            );
        },
        onCancel: () => {
            drag.current = null;
            setDragging(false);
        },
    });

    const touchVisit = (
        e: TouchEvent<HTMLElement>,
        visit: CalendarVisit,
        lane: Lane,
        grabOffset: number,
    ) => {
        if (visit.movable) {
            touch.begin(
                e,
                { kind: 'visit', visit, fromLane: lane.id, grabOffset },
                `${visit.start_time} ${visit.job.customer ?? ''}`,
            );
        }
    };

    const open = (visit: CalendarVisit) => {
        if (!touch.consumeClick()) {
            setDetails(visit);
        }
    };

    const dragVisit = (
        e: DragEvent,
        visit: CalendarVisit,
        lane: Lane,
        grabOffset: number,
    ) => startDrag(e, { kind: 'visit', visit, fromLane: lane.id, grabOffset });

    const title =
        view !== 'week'
            ? dayLabel(date, locale, {
                  weekday: 'long',
                  month: 'long',
                  day: 'numeric',
                  year: 'numeric',
              })
            : t('calendar.week_of', {
                  date: dayLabel(days[0], locale, {
                      month: 'long',
                      day: 'numeric',
                      year: 'numeric',
                  }),
              });

    const people = lanes.filter((lane) => lane.id !== null);

    return (
        <>
            <Head title={t('calendar.title')} />

            <div className="space-y-4 p-4">
                <PageHeader title={t('calendar.title')} description={title} />

                <div className="flex flex-wrap items-center gap-2">
                    <div className="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                        {(['day', 'week', 'map'] as const).map((v) => (
                            <Link
                                key={v}
                                href={calendar({ query: { view: v, date } })}
                                preserveScroll
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-center text-sm font-medium',
                                    view === v
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {t(`calendar.${v}`)}
                            </Link>
                        ))}
                    </div>
                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label={t('calendar.previous')}
                            onClick={() => go({ date: previous })}
                        >
                            <ChevronLeft />
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => go({ date: today })}
                            disabled={days.includes(today)}
                        >
                            {t('calendar.today')}
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label={t('calendar.next')}
                            onClick={() => go({ date: next })}
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                    <Input
                        type="date"
                        className="w-auto"
                        aria-label={t('jobs.visit_fields.date')}
                        value={date}
                        onChange={(e) =>
                            e.target.value && go({ date: e.target.value })
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('calendar.drag_hint')}{' '}
                        {t('calendar.travel_buffer', {
                            minutes: travelBuffer,
                        })}
                    </p>
                </div>

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
                    <div className="min-w-0">
                        {view === 'map' ? (
                            <DayMap
                                visits={visits}
                                lanes={lanes}
                                onOpen={open}
                            />
                        ) : view === 'day' ? (
                            <DayGrid
                                date={date}
                                lanes={lanes}
                                visits={visits}
                                hours={hours}
                                travelBuffer={travelBuffer}
                                dragging={dragging}
                                onVisitDragStart={dragVisit}
                                onDragEnd={endDrag}
                                onVisitTouchStart={touchVisit}
                                onDrop={drop}
                                onOpen={open}
                            />
                        ) : (
                            <WeekGrid
                                days={days}
                                today={today}
                                lanes={lanes}
                                visits={visits}
                                travelBuffer={travelBuffer}
                                dragging={dragging}
                                onVisitDragStart={dragVisit}
                                onDragEnd={endDrag}
                                onVisitTouchStart={touchVisit}
                                onDrop={drop}
                                onOpen={open}
                            />
                        )}
                    </div>

                    <aside className="space-y-4">
                        <section className="space-y-2 rounded-lg border p-3">
                            <h2 className="text-sm font-medium">
                                {t('calendar.to_schedule')}
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                {t('calendar.to_schedule_hint')}
                            </p>
                            {unscheduled.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('calendar.nothing_to_schedule')}
                                </p>
                            ) : (
                                <ul className="max-h-96 space-y-2 overflow-y-auto">
                                    {unscheduled.map((job) => (
                                        <li key={job.id}>
                                            <button
                                                type="button"
                                                draggable
                                                onDragStart={(e) =>
                                                    startDrag(e, {
                                                        kind: 'job',
                                                        job,
                                                    })
                                                }
                                                onDragEnd={endDrag}
                                                onTouchStart={(e) =>
                                                    touch.begin(
                                                        e,
                                                        { kind: 'job', job },
                                                        `#${job.number} ${job.customer ?? ''}`,
                                                    )
                                                }
                                                onContextMenu={(e) =>
                                                    e.preventDefault()
                                                }
                                                onClick={() =>
                                                    !touch.consumeClick() &&
                                                    setScheduling(job)
                                                }
                                                className="block w-full cursor-grab rounded-md border bg-card p-2 text-left text-xs shadow-sm select-none [-webkit-touch-callout:none] hover:bg-muted/50"
                                            >
                                                <span className="flex items-center justify-between gap-2">
                                                    <span className="font-medium">
                                                        #{job.number}{' '}
                                                        {job.customer}
                                                    </span>
                                                    <StatusBadge
                                                        status={job.status}
                                                        label={job.status_label}
                                                    />
                                                </span>
                                                <span className="block truncate text-muted-foreground">
                                                    {[
                                                        job.job_type_label,
                                                        job.appliances.join(
                                                            ', ',
                                                        ),
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                                <span className="block truncate text-muted-foreground">
                                                    {job.address}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {view === 'day' && (
                            <section className="space-y-3 rounded-lg border p-3">
                                <div>
                                    <h2 className="text-sm font-medium">
                                        {t('calendar.route')}
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        {t('calendar.route_hint')}
                                    </p>
                                </div>
                                {people.map((lane) => {
                                    const stops = visits
                                        .filter(
                                            (v) =>
                                                v.date === date &&
                                                inLane(v, lane),
                                        )
                                        .sort(
                                            (a, b) =>
                                                a.start_minutes -
                                                b.start_minutes,
                                        );

                                    if (stops.length === 0) {
                                        return null;
                                    }

                                    const addresses = stops
                                        .map((v) => v.job.address)
                                        .filter((a): a is string => !!a);

                                    return (
                                        <div
                                            key={lane.id}
                                            className="space-y-1"
                                        >
                                            <h3 className="text-sm font-medium">
                                                {lane.name}
                                            </h3>
                                            <ol className="space-y-1 text-xs">
                                                {stops.map((v, i) => (
                                                    <li
                                                        key={v.id}
                                                        className="flex gap-2"
                                                    >
                                                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-[10px] text-primary-foreground">
                                                            {i + 1}
                                                        </span>
                                                        <span className="min-w-0">
                                                            <span className="font-medium">
                                                                {v.start_time}–
                                                                {v.end_time}{' '}
                                                                {v.job.customer}
                                                            </span>
                                                            <span className="block text-muted-foreground">
                                                                {v.job.address}
                                                            </span>
                                                        </span>
                                                    </li>
                                                ))}
                                            </ol>
                                            {addresses.length > 0 && (
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <a
                                                        href={routeUrl(
                                                            addresses,
                                                        )}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <Navigation />{' '}
                                                        {t(
                                                            'calendar.open_route',
                                                        )}
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    );
                                })}
                                {!visits.some((v) => v.date === date) && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('calendar.no_route')}
                                    </p>
                                )}
                            </section>
                        )}
                    </aside>
                </div>
            </div>

            {touch.ghost && (
                <div
                    className="pointer-events-none fixed z-50 max-w-48 -translate-x-1/2 -translate-y-full rounded-md border-l-4 border-l-primary bg-card px-2 py-1 text-xs font-medium shadow-lg"
                    style={{ left: touch.ghost.x, top: touch.ghost.y - 12 }}
                >
                    {touch.ghost.label}
                </div>
            )}

            <Dialog
                open={details !== null}
                onOpenChange={(open) => !open && setDetails(null)}
            >
                <DialogContent className="sm:max-w-md">
                    {details && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    #{details.job.number} {details.job.customer}
                                </DialogTitle>
                                <DialogDescription>
                                    {dayLabel(details.date, locale)} ·{' '}
                                    {details.start_time}–{details.end_time}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-2 text-sm">
                                <div className="flex flex-wrap gap-2">
                                    <StatusBadge
                                        status={details.job.status}
                                        label={details.job.status_label}
                                    />
                                    <span className="text-muted-foreground">
                                        {details.job.job_type_label}
                                        {details.job.appliances.length > 0 &&
                                            ` · ${details.job.appliances.join(', ')}`}
                                    </span>
                                </div>
                                {details.job.address && (
                                    <p className="flex gap-2">
                                        <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        {details.job.address}
                                    </p>
                                )}
                                <p className="text-muted-foreground">
                                    {details.assignees.length > 0
                                        ? details.assignees
                                              .map((a) => a.name)
                                              .join(', ')
                                        : t('calendar.unassigned')}
                                </p>
                                {details.conflict && (
                                    <p className="flex gap-2 text-destructive">
                                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                        {t('calendar.conflict', {
                                            minutes: travelBuffer,
                                        })}
                                    </p>
                                )}
                                {!details.movable && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('calendar.not_movable')}
                                    </p>
                                )}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <Button asChild variant="outline">
                                    <Link href={showJob(details.job.id)}>
                                        {t('calendar.open_job')}
                                    </Link>
                                </Button>
                                <Button
                                    onClick={() => {
                                        setEditing(details);
                                        setDetails(null);
                                    }}
                                >
                                    <Pencil /> {t('jobs.edit_visit')}
                                </Button>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <VisitDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                jobId={editing?.job.id ?? 0}
                visit={editing}
                today={date}
                assignableUsers={assignableUsers}
                stayOnPage
            />

            <VisitDialog
                open={scheduling !== null}
                onOpenChange={(open) => !open && setScheduling(null)}
                jobId={scheduling?.id ?? 0}
                visit={null}
                today={date}
                assignableUsers={assignableUsers}
                initial={{
                    start_time: toTime(hours.start * 60),
                    end_time: toTime(hours.start * 60 + NEW_WINDOW),
                }}
                stayOnPage
            />
        </>
    );
}

CalendarPage.layout = {
    breadcrumbs: [{ title: 'calendar.title', href: calendar() }],
};
