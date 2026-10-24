import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    MapPin,
    Navigation,
    Pencil,
    SlidersHorizontal,
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
import { WeekAgenda } from '@/components/calendar/week-agenda';
import { WeekGrid } from '@/components/calendar/week-grid';
import { StatusBadge } from '@/components/jobs/status-badge';
import { TechnicianPicker } from '@/components/jobs/technician-picker';
import type { Assignable } from '@/components/jobs/types';
import { isFinishedJob } from '@/components/jobs/types';
import { VisitDialog } from '@/components/jobs/visit-dialog';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTouchDrag } from '@/hooks/use-touch-drag';
import { useClock } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { useLocale } from '@/lib/locale';
import { calendar } from '@/routes';
import { create as bookCustomer, show as showJob } from '@/routes/jobs';
import {
    move,
    store as storeVisit,
    transfer as transferVisits,
} from '@/routes/visits';

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
    nowMinutes?: number;
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
    visits: allVisits,
    unscheduled,
    assignableUsers,
    nowMinutes = 0,
}: Props) {
    const t = useTrans();
    // Solo: every job is the Owner's, so there is nobody to transfer to.
    const worksAlone = usePage().props.auth.company?.works_alone ?? false;
    // Finished work is hidden by default; "Show completed" brings it back (greyed out). Remembered per device.
    const [showCompleted, setShowCompleted] = useState(() => {
        try {
            return localStorage.getItem('calendar.showCompleted') === '1';
        } catch {
            return false;
        }
    });
    const toggleCompleted = (on: boolean) => {
        setShowCompleted(on);

        try {
            localStorage.setItem('calendar.showCompleted', on ? '1' : '0');
        } catch {
            // Storage unavailable (private mode): the choice lasts for this page only.
        }
    };
    // Filters (UX-REDESIGN §9.1): Show completed and the people shown, in a sheet; remembered per device.
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [hiddenPeople, setHiddenPeople] = useState<number[]>(() => {
        try {
            return JSON.parse(
                localStorage.getItem('calendar.hiddenPeople') ?? '[]',
            ) as number[];
        } catch {
            return [];
        }
    });
    const togglePerson = (id: number) => {
        const next = hiddenPeople.includes(id)
            ? hiddenPeople.filter((p) => p !== id)
            : [...hiddenPeople, id];
        setHiddenPeople(next);
        try {
            localStorage.setItem('calendar.hiddenPeople', JSON.stringify(next));
        } catch {
            // Storage unavailable: the choice lasts for this page only.
        }
    };
    const shownLanes = lanes.filter(
        (lane) => lane.id === null || !hiddenPeople.includes(lane.id),
    );
    // The drag hint is shown once, until "Got it".
    const [hintSeen, setHintSeen] = useState(() => {
        try {
            return localStorage.getItem('calendar.hintSeen') === '1';
        } catch {
            return false;
        }
    });
    const dismissHint = () => {
        setHintSeen(true);
        try {
            localStorage.setItem('calendar.hintSeen', '1');
        } catch {
            // Storage unavailable: hidden for this page only.
        }
    };
    const visits = showCompleted
        ? allVisits
        : allVisits.filter((visit) => !isFinishedJob(visit.job.status));
    const hiddenCount = allVisits.length - visits.length;
    const clock = useClock();
    const locale = useLocale();
    const drag = useRef<DragItem | null>(null);
    const [dragging, setDragging] = useState(false);
    const [details, setDetails] = useState<CalendarVisit | null>(null);
    const [editing, setEditing] = useState<CalendarVisit | null>(null);
    const [scheduling, setScheduling] = useState<UnscheduledJob | null>(null);
    // "Transfer" (owner's fixes, October 19): tick jobs of one person's lane, then "Move to…" another person.
    const [transferLane, setTransferLane] = useState<number | null | undefined>(
        undefined,
    );
    const [selected, setSelected] = useState<number[]>([]);
    const [moveOpen, setMoveOpen] = useState(false);
    const stopTransfer = () => {
        setTransferLane(undefined);
        setSelected([]);
        setMoveOpen(false);
    };
    const moveSelected = (userId: number | null) => {
        if (userId === null) return;
        setMoveOpen(false);
        router.post(
            transferVisits().url,
            {
                visit_ids: selected,
                from_user_id: transferLane ?? null,
                to_user_id: userId,
            },
            {
                ...options,
                // What is left in the lane can be ticked and given to someone else.
                onSuccess: () => setSelected([]),
            },
        );
    };
    const oneSelected =
        selected.length === 1
            ? visits.find((v) => v.id === selected[0])
            : undefined;

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
                `${clock.clock(visit.start_time)} ${visit.job.customer ?? ''}`,
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

    const people = shownLanes.filter((lane) => lane.id !== null);

    const toSchedule = (
        <>
            <p className="mb-2 text-xs text-muted-foreground">
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
                                onContextMenu={(e) => e.preventDefault()}
                                onClick={() =>
                                    !touch.consumeClick() && setScheduling(job)
                                }
                                className="block w-full cursor-grab rounded-md border bg-card p-2 text-left text-xs shadow-sm select-none [-webkit-touch-callout:none] hover:bg-muted/50"
                            >
                                <span className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        #{job.number} {job.customer}
                                    </span>
                                    <StatusBadge
                                        status={job.status}
                                        label={job.status_label}
                                    />
                                </span>
                                <span className="block truncate text-muted-foreground">
                                    {[
                                        job.job_type_label,
                                        job.appliances.join(', '),
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
        </>
    );

    return (
        <>
            <Head title={t('calendar.title')} />

            <ScreenHeader
                title={t('calendar.title')}
                subtitle={title}
                actions={
                    <button
                        type="button"
                        className={headerButtonClass}
                        aria-label={t('calendar.filters')}
                        onClick={() => setFiltersOpen(true)}
                        data-test="calendar-filters"
                    >
                        <SlidersHorizontal className="size-5" />
                    </button>
                }
            />

            <div className="space-y-3 p-4">
                {/* One line: Day | Week | Map, ‹ Today ›, and the date (tap to pick one). */}
                <div className="flex flex-wrap items-center gap-2">
                    <SegmentedControl
                        label={t('calendar.title')}
                        value={view}
                        onChange={(v) =>
                            router.get(
                                calendar().url,
                                { view: v, date },
                                { preserveScroll: true },
                            )
                        }
                        options={(['day', 'week', 'map'] as const).map((v) => ({
                            value: v,
                            label: t(`calendar.${v}`),
                        }))}
                    />
                    <div className="flex items-center">
                        <button
                            type="button"
                            className="da-press flex size-10 items-center justify-center rounded-xl"
                            aria-label={t('calendar.previous')}
                            onClick={() => go({ date: previous })}
                        >
                            <ChevronLeft className="size-5" />
                        </button>
                        {!days.includes(today) && (
                            <Button
                                variant="tertiary"
                                size="sm"
                                onClick={() => go({ date: today })}
                            >
                                {t('calendar.today')}
                            </Button>
                        )}
                        <label className="relative flex min-h-10 cursor-pointer items-center px-1 text-sm font-semibold whitespace-nowrap text-link underline decoration-dotted underline-offset-4">
                            <span data-test="calendar-date">
                                {days.includes(today) && view !== 'week'
                                    ? t('calendar.today')
                                    : dayLabel(date, locale)}
                            </span>
                            <input
                                type="date"
                                value={date}
                                aria-label={t('jobs.visit_fields.date')}
                                className="absolute inset-0 cursor-pointer opacity-0"
                                onClick={(e) => {
                                    try {
                                        e.currentTarget.showPicker();
                                    } catch {
                                        // Older browsers open it by themselves.
                                    }
                                }}
                                onChange={(e) =>
                                    e.target.value &&
                                    go({ date: e.target.value })
                                }
                            />
                        </label>
                        <button
                            type="button"
                            className="da-press flex size-10 items-center justify-center rounded-xl"
                            aria-label={t('calendar.next')}
                            onClick={() => go({ date: next })}
                        >
                            <ChevronRight className="size-5" />
                        </button>
                    </div>
                </div>

                {!hintSeen && view !== 'map' && (
                    <div
                        className="da-card flex items-center gap-3 p-3 text-sm"
                        data-test="calendar-hint"
                    >
                        <p className="min-w-0 flex-1 text-muted-foreground">
                            {t('calendar.drag_hint')}
                        </p>
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={dismissHint}
                        >
                            {t('calendar.got_it')}
                        </Button>
                    </div>
                )}

                {/* Phones: the jobs to schedule fold above the grid (§9.2); on wide screens they are a column. */}
                <details
                    className="da-card group xl:hidden"
                    data-test="to-schedule-phone"
                >
                    <summary className="flex min-h-12 cursor-pointer list-none items-center gap-2 px-4 text-sm font-semibold [&::-webkit-details-marker]:hidden">
                        <span className="flex-1">
                            {t('calendar.to_schedule_count', {
                                count: unscheduled.length,
                            })}
                        </span>
                        <ChevronDown className="size-5 transition-transform group-open:rotate-180" />
                    </summary>
                    <div className="border-t p-3">{toSchedule}</div>
                </details>

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
                    <div className="min-w-0">
                        {view === 'map' ? (
                            <DayMap
                                visits={visits}
                                lanes={shownLanes}
                                onOpen={open}
                            />
                        ) : view === 'day' ? (
                            <DayGrid
                                date={date}
                                lanes={shownLanes}
                                nowMinutes={date === today ? nowMinutes : null}
                                onEmptySlot={(lane, minutes) =>
                                    router.visit(
                                        bookCustomer({
                                            query: {
                                                book: 1,
                                                date,
                                                start: toTime(
                                                    Math.floor(minutes / 30) *
                                                        30,
                                                ),
                                                ...(lane.id !== null
                                                    ? { technician: lane.id }
                                                    : {}),
                                            },
                                        }).url,
                                    )
                                }
                                visits={visits}
                                hours={hours}
                                travelBuffer={travelBuffer}
                                dragging={dragging}
                                onVisitDragStart={dragVisit}
                                onDragEnd={endDrag}
                                onVisitTouchStart={touchVisit}
                                onDrop={drop}
                                onOpen={open}
                                transferLane={transferLane}
                                selected={selected}
                                onTransfer={
                                    worksAlone
                                        ? undefined
                                        : (lane) => {
                                              setSelected([]);
                                              setTransferLane(lane.id);
                                          }
                                }
                                onToggle={(visit) =>
                                    setSelected((ids) =>
                                        ids.includes(visit.id)
                                            ? ids.filter(
                                                  (id) => id !== visit.id,
                                              )
                                            : [...ids, visit.id],
                                    )
                                }
                            />
                        ) : (
                            <>
                                <div className="md:hidden">
                                    <WeekAgenda
                                        days={days}
                                        today={today}
                                        visits={visits}
                                        onOpen={open}
                                        onReschedule={setEditing}
                                    />
                                </div>
                                <div className="hidden md:block">
                                    <WeekGrid
                                        days={days}
                                        today={today}
                                        lanes={shownLanes}
                                        visits={visits}
                                        travelBuffer={travelBuffer}
                                        dragging={dragging}
                                        onVisitDragStart={dragVisit}
                                        onDragEnd={endDrag}
                                        onVisitTouchStart={touchVisit}
                                        onDrop={drop}
                                        onOpen={open}
                                    />
                                </div>
                            </>
                        )}
                    </div>

                    <aside className="space-y-4">
                        <section className="da-card hidden space-y-2 p-3 xl:block">
                            <h2 className="text-sm font-medium">
                                {t('calendar.to_schedule')}
                            </h2>
                            {toSchedule}
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
                                                                {clock.range(
                                                                    v.start_time,
                                                                    v.end_time,
                                                                )}{' '}
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

            <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
                <SheetContent
                    side="bottom"
                    className="gap-3 px-4 pt-4"
                    data-test="calendar-filters-sheet"
                >
                    <SheetHeader className="p-0 pr-12">
                        <SheetTitle>{t('calendar.filters')}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {t('calendar.filters')}
                        </SheetDescription>
                    </SheetHeader>
                    <label className="flex min-h-11 items-center gap-2 text-sm">
                        <Checkbox
                            checked={showCompleted}
                            onCheckedChange={(c) => toggleCompleted(c === true)}
                        />
                        {t('calendar.show_completed')}
                        {!showCompleted && hiddenCount > 0 && (
                            <span className="text-muted-foreground">
                                ({hiddenCount})
                            </span>
                        )}
                    </label>
                    <div className="space-y-1">
                        <p className="text-sm font-semibold">
                            {t('calendar.people')}
                        </p>
                        {lanes
                            .filter((lane) => lane.id !== null)
                            .map((lane) => (
                                <label
                                    key={lane.id}
                                    className="flex min-h-11 items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={
                                            !hiddenPeople.includes(lane.id!)
                                        }
                                        onCheckedChange={() =>
                                            togglePerson(lane.id!)
                                        }
                                    />
                                    {lane.name}
                                </label>
                            ))}
                    </div>
                    <p className="flex items-center gap-2 pb-[env(safe-area-inset-bottom)] text-xs text-muted-foreground">
                        <span
                            className="inline-block h-4 w-8 rounded"
                            style={{
                                backgroundImage:
                                    'repeating-linear-gradient(135deg, var(--muted-foreground) 0 1px, transparent 1px 6px)',
                            }}
                        />
                        {t('calendar.travel_buffer', {
                            minutes: travelBuffer,
                        })}
                    </p>
                </SheetContent>
            </Sheet>

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
                                    {clock.range(
                                        details.start_time,
                                        details.end_time,
                                    )}
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

            {transferLane !== undefined && view === 'day' && (
                <StickyActionBar>
                    <p
                        className="flex min-h-12 items-center text-sm font-medium"
                        role="status"
                        data-test="transfer-status"
                    >
                        {selected.length > 0
                            ? t('jobs.transfer.selected', {
                                  count: selected.length,
                              })
                            : t('jobs.transfer.select_hint')}
                    </p>
                    <Button
                        disabled={selected.length === 0}
                        onClick={() => setMoveOpen(true)}
                        data-test="transfer-move"
                    >
                        {t('jobs.transfer.move_to')}
                    </Button>
                    <Button variant="outline" onClick={stopTransfer}>
                        {t('jobs.transfer.done')}
                    </Button>
                </StickyActionBar>
            )}
            <TechnicianPicker
                open={moveOpen}
                onOpenChange={setMoveOpen}
                date={date}
                window={
                    oneSelected
                        ? {
                              start: oneSelected.start_time,
                              end: oneSelected.end_time,
                          }
                        : null
                }
                excludeVisitIds={selected}
                excludeUserId={transferLane ?? null}
                title={t('jobs.transfer.move_title', {
                    count: selected.length,
                })}
                onPick={moveSelected}
            />

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
