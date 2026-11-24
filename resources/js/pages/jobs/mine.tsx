import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Car,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Download,
    PackageCheck,
    Play,
    Search,
    UserX,
    Mic,
    Plus,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { InboxHeaderButton } from '@/components/inbox-header-button';
import { cardButton, JobCard } from '@/components/jobs/job-card';
import { JobAddressSheet } from '@/components/jobs/job-address-sheet';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { TechnicianPicker } from '@/components/jobs/technician-picker';
import { TaskList } from '@/components/tasks/task-list';
import { TaskSheet } from '@/components/tasks/task-sheet';
import type { TaskDraft, TaskItem } from '@/components/tasks/types';
import { MicButton } from '@/components/ui/mic-button';
import { xsrfToken } from '@/lib/http';
import type { Visit } from '@/components/jobs/types';
import { openSmsApp } from '@/components/messaging/job-messaging';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { FilterChips } from '@/components/ui/filter-chips';
import { Input } from '@/components/ui/input';
import { ListRow } from '@/components/ui/list-row';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { useInstallPrompt } from '@/hooks/use-install-prompt';
import { useCompanyTime } from '@/lib/datetime';
import { currentPosition } from '@/lib/geo';
import { useTrans } from '@/lib/i18n';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { calendar } from '@/routes';
import { mine, panes as panesRoute, show, technician } from '@/routes/jobs';
import { parse as parseTask } from '@/routes/tasks';
import { finishScreen, onMyWay, start } from '@/routes/visits';

type MyVisit = Visit & {
    can_work?: boolean;
    can_finish?: boolean;
    can_edit?: boolean;
    on_my_way_sms?: { to: string; body: string } | null;
    job: {
        id: number;
        number: number;
        status: string;
        status_label: string;
        job_type_label: string;
        visit_type: string;
        visit_type_label: string;
        bring: { done: number; total: number } | null;
        customer: string | null;
        customer_icon: AvatarIcon;
        phone: string | null;
        address: string | null;
        appliances: string[];
        appliance_types: string[];
        picture: string | null;
        problem: string | null;
        new_messages?: number;
    };
};

type Tab = 'day' | 'completed';

type Panes = 'jobs' | 'todo' | 'both';

/** Tablets and computers start with both columns. */
const WIDE = 768;

/** "123 Main St, Burnaby, BC V5H 1A1" → "123 Main St". */
const shortAddress = (address: string | null) =>
    address ? address.split(',')[0].trim() : null;

/** Status filter chips appear only on a busy day. */
const CHIPS_FROM = 4;

/** "2026-10-09" moved by whole days. */
const shiftDay = (ymd: string, by: number) => {
    const date = new Date(`${ymd}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + by);

    return date.toISOString().slice(0, 10);
};

/**
 * My Jobs (UX-REDESIGN §6.1, §24): [Day | Completed] with ‹ date › on one line, the day in one sentence
 * ("3 jobs · next at 1 p.m."), compact cards with Navigate, Call and the visit step (On my way, Start job,
 * Finish visit) and the next job outlined. Trips and tasks start from the round "+" (CreateFab).
 */
export default function MyJobs({
    tab,
    date,
    today,
    search: initialSearch = '',
    visits,
    othersOnDay = 0,
    tasks = [],
    panes: savedPanes = null,
    canTasks = false,
}: {
    tab: Tab;
    panes?: Panes | null;
    canTasks?: boolean;
    tasks?: TaskItem[];
    othersOnDay?: number;
    date: string;
    today: string;
    search?: string;
    visits: MyVisit[];
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const install = useInstallPrompt();
    const [editingTask, setEditingTask] = useState<TaskItem | null>(null);
    // Jobs / To Do in the header (owner's task of November 19): one is always on; the last choice is kept.
    const [panes, setPanes] = useState<Panes>(() =>
        !canTasks
            ? 'jobs'
            : (savedPanes ??
              (typeof window !== 'undefined' && window.innerWidth >= WIDE
                  ? 'both'
                  : 'jobs')),
    );
    const showJobs = panes !== 'todo';
    const showTodo = canTasks && panes !== 'jobs';
    const both = showJobs && showTodo;
    const togglePane = (pane: 'jobs' | 'todo') => {
        const jobsOn = pane === 'jobs' ? !showJobs : showJobs;
        const todoOn = pane === 'todo' ? !showTodo : showTodo;
        // Both off is not allowed: the last one on stays on.
        if (!jobsOn && !todoOn) return;
        const next: Panes =
            jobsOn && todoOn ? 'both' : jobsOn ? 'jobs' : 'todo';
        setPanes(next);
        router.put(
            panesRoute().url,
            { panes: next },
            { preserveState: true, preserveScroll: true, only: ['panes'] },
        );
    };
    const [newTask, setNewTask] = useState<{ draft: TaskDraft | null } | null>(
        null,
    );
    const [quickTask, setQuickTask] = useState('');
    const [quickBusy, setQuickBusy] = useState(false);
    const [quickNote, setQuickNote] = useState<string | null>(null);
    const readQuickTask = async () => {
        const text = quickTask.trim();
        if (text === '') {
            setNewTask({ draft: null });
            return;
        }
        setQuickBusy(true);
        setQuickNote(null);
        try {
            const response = await fetch(parseTask().url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ text }),
            });
            const body = (await response.json()) as {
                task?: TaskDraft;
                message?: string;
            };
            if (body.task) {
                setQuickTask('');
                setNewTask({ draft: body.task });
            } else {
                setQuickNote(body.message ?? t('tasks.parse_failed'));
                setNewTask({
                    draft: {
                        title: text.slice(0, 160),
                        task_date: date,
                        all_day: true,
                        start_time: null,
                        address: null,
                        kind: null,
                    },
                });
            }
        } catch {
            setQuickNote(t('tasks.parse_failed'));
        } finally {
            setQuickBusy(false);
        }
    };
    const { auth } = usePage().props;
    // The search runs on the server over both tabs; it stays open while switching tabs.
    const [searchOpen, setSearchOpen] = useState(initialSearch !== '');
    const [search, setSearch] = useState(initialSearch);
    const [busy, setBusy] = useState<number | null>(null);
    const dateInput = useRef<HTMLInputElement>(null);
    const firstRender = useRef(true);
    const term = search.trim();
    const query = (next: { tab: Tab; date?: string }) => ({
        tab: next.tab,
        ...(next.tab === 'day' && next.date && next.date !== today
            ? { date: next.date }
            : {}),
        ...(term ? { search: term } : {}),
    });

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = setTimeout(() => {
            router.get(mine().url, query({ tab, date }), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 350);
        return () => clearTimeout(timer);
    }, [search]);

    const [status, setStatus] = useState('');
    // "Not assigned" (office, team mode): one tap opens the technician choice.
    const canAssign =
        (auth.can?.viewCalendar ?? false) && !auth.company?.works_alone;
    const [assigning, setAssigning] = useState<MyVisit | null>(null);
    // A job booked without an address: Navigate and On my way open the address entry first.
    const [addressFor, setAddressFor] = useState<number | null>(null);
    // A plain date: formatted as it is, without a time zone.
    const plainDay = (options: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat(auth.company?.locale ?? 'en-US', {
            ...options,
            timeZone: 'UTC',
        }).format(new Date(`${date}T00:00:00Z`));
    // The header already shows the date; the label stays short so the row fits a phone.
    const dayLabel =
        date === today
            ? t('jobs.tabs.today')
            : plainDay({ weekday: 'short', month: 'short', day: 'numeric' });
    const goTo = (next: { tab: Tab; date?: string }) =>
        router.get(mine().url, query(next), { preserveScroll: true });

    // Status chips over this tab's jobs; a tap shows only that status, a second tap shows all.
    const statusCounts: Record<string, number> = {};
    const statusLabels: Record<string, string> = {};
    visits.forEach((visit) => {
        statusCounts[visit.job.status] =
            (statusCounts[visit.job.status] ?? 0) + 1;
        statusLabels[visit.job.status] = visit.job.status_label;
    });
    const showChips = visits.length >= CHIPS_FROM;
    const shown = visits.filter(
        (visit) => !showChips || status === '' || visit.job.status === status,
    );

    const now = Date.now();
    const overdue = (visit: MyVisit) =>
        visit.status === 'scheduled' &&
        new Date(visit.scheduled_end).getTime() < now;
    // The next job: the one under way, else the first still to come (or late) on this day.
    const next =
        tab === 'day'
            ? (visits.find((v) =>
                  ['on_the_way', 'in_progress'].includes(v.status),
              ) ?? visits.find((v) => v.status === 'scheduled'))
            : undefined;
    const summary =
        tab === 'completed'
            ? `${t('jobs.mine_summary.last_30_days')} · ${t('jobs.mine_summary.jobs', { count: visits.length })}`
            : [
                  t('jobs.mine_summary.jobs', { count: visits.length }),
                  next?.status === 'scheduled' &&
                      t('jobs.mine_summary.next_at', {
                          time: time.time(next.scheduled_start),
                      }),
              ]
                  .filter(Boolean)
                  .join(' · ');

    const act = (visit: MyVisit, url: string, data: object = {}) => {
        setBusy(visit.id);
        router.post(url, data as never, {
            preserveScroll: true,
            onError: (errors) =>
                toast.error(String(Object.values(errors)[0] ?? '')),
            onFinish: () => setBusy(null),
        });
    };

    const step = (visit: MyVisit) => {
        if (tab !== 'day') {
            return undefined;
        }
        // Finish visit of a started visit: also once the job is paid.
        if (visit.status === 'in_progress' && visit.can_finish) {
            return (
                <Button
                    variant="outline"
                    className={cardButton}
                    asChild
                    data-test="card-finish"
                >
                    <Link href={finishScreen(visit.id)}>
                        <CheckCircle2 /> {t('jobs.actions.complete')}
                    </Link>
                </Button>
            );
        }
        if (!visit.can_work) {
            return undefined;
        }
        const props = {
            variant: 'outline' as const,
            className: cardButton,
            loading: busy === visit.id,
        };

        if (visit.status === 'scheduled') {
            return (
                <Button
                    {...props}
                    data-test="card-on-my-way"
                    onClick={() => {
                        if (!visit.job.address) {
                            setAddressFor(visit.job.id);
                            return;
                        }
                        // From the technician's phone: the messages app opens with the text ready.
                        const sms = visit.on_my_way_sms;
                        if (sms) {
                            act(visit, onMyWay(visit.id).url, {
                                phone_sms: sms,
                            });
                            openSmsApp(sms.to, sms.body);

                            return;
                        }
                        // The app texts the customer: with the drive time from here when the phone knows where it is.
                        setBusy(visit.id);
                        void currentPosition().then((position) =>
                            act(visit, onMyWay(visit.id).url, position ?? {}),
                        );
                    }}
                >
                    <Car /> {t('jobs.actions.on_my_way')}
                </Button>
            );
        }

        if (visit.status === 'on_the_way') {
            return (
                <Button
                    {...props}
                    onClick={() => act(visit, start(visit.id).url)}
                >
                    <Play /> {t('jobs.actions.start')}
                </Button>
            );
        }

        return undefined;
    };

    return (
        <>
            <Head title={t('jobs.my_jobs')} />

            <ScreenHeader
                title={t('jobs.my_jobs')}
                titleControl={
                    canTasks ? (
                        <div
                            className="flex gap-1.5"
                            role="group"
                            aria-label={t('tasks.panes.label')}
                            data-test="today-panes"
                        >
                            {(['jobs', 'todo'] as const).map((pane) => {
                                const on =
                                    pane === 'jobs' ? showJobs : showTodo;

                                return (
                                    <button
                                        key={pane}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => togglePane(pane)}
                                        data-test={`pane-${pane}`}
                                        className={cn(
                                            'min-h-9 rounded-full px-3.5 text-sm font-semibold transition-colors',
                                            on
                                                ? 'bg-white text-slate-900 shadow-[inset_0_1px_0_rgba(255,255,255,.8),0_3px_8px_rgba(0,0,0,.25)]'
                                                : 'bg-white/10 text-white/80 ring-1 ring-white/30',
                                        )}
                                    >
                                        {t(`tasks.panes.${pane}`)}
                                    </button>
                                );
                            })}
                        </div>
                    ) : undefined
                }
                subtitle={plainDay({
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                })}
                actions={
                    <>
                        <InboxHeaderButton />
                        <button
                            type="button"
                            className={headerButtonClass}
                            aria-label={t('common.search')}
                            aria-expanded={searchOpen}
                            onClick={() => {
                                if (searchOpen) setSearch('');
                                setSearchOpen(!searchOpen);
                            }}
                        >
                            <Search className="size-5" />
                        </button>
                    </>
                }
            />

            <div
                className={cn(
                    'mx-auto w-full space-y-3 p-4 pb-24 sm:p-6',
                    both ? 'max-w-6xl' : 'max-w-2xl',
                )}
            >
                {install && (
                    <Button variant="outline" onClick={() => void install()}>
                        <Download /> {t('jobs.install.button')}
                    </Button>
                )}

                {searchOpen && (
                    <Input
                        type="search"
                        autoFocus
                        value={search}
                        placeholder={t('jobs.search')}
                        aria-label={t('common.search')}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                )}

                {/* One line: the tab on the left, the day on the right (‹ › and a tap on the date to pick one). */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <SegmentedControl
                        label={t('jobs.my_jobs')}
                        value={tab}
                        onChange={(next) => goTo({ tab: next, date })}
                        options={[
                            { value: 'day', label: t('jobs.tabs.day') },
                            {
                                value: 'completed',
                                label: t('jobs.tabs.completed'),
                            },
                        ]}
                    />
                    {tab === 'day' && (
                        <div className="flex shrink-0 items-center">
                            <button
                                type="button"
                                aria-label={t('jobs.tabs.previous_day')}
                                className="da-press flex size-10 shrink-0 items-center justify-center rounded-xl"
                                onClick={() =>
                                    goTo({
                                        tab: 'day',
                                        date: shiftDay(date, -1),
                                    })
                                }
                            >
                                <ChevronLeft className="size-5" />
                            </button>
                            <label className="relative flex min-h-11 cursor-pointer items-center px-1 text-sm font-semibold whitespace-nowrap text-link underline decoration-dotted underline-offset-4">
                                <span data-test="day-label">{dayLabel}</span>
                                <input
                                    ref={dateInput}
                                    type="date"
                                    value={date}
                                    aria-label={t('jobs.tabs.pick_day')}
                                    className="absolute inset-0 cursor-pointer opacity-0"
                                    onClick={(e) => {
                                        try {
                                            e.currentTarget.showPicker();
                                        } catch {
                                            // Older browsers open the picker by themselves.
                                        }
                                    }}
                                    onChange={(e) =>
                                        e.target.value &&
                                        goTo({
                                            tab: 'day',
                                            date: e.target.value,
                                        })
                                    }
                                />
                            </label>
                            <button
                                type="button"
                                aria-label={t('jobs.tabs.next_day')}
                                className="da-press flex size-10 shrink-0 items-center justify-center rounded-xl"
                                onClick={() =>
                                    goTo({
                                        tab: 'day',
                                        date: shiftDay(date, 1),
                                    })
                                }
                            >
                                <ChevronRight className="size-5" />
                            </button>
                        </div>
                    )}
                </div>

                <div
                    className={cn(both && 'grid grid-cols-2 items-start gap-3')}
                >
                    {showJobs && (
                        <section
                            className="min-w-0 space-y-3"
                            data-test="jobs-pane"
                        >
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="day-summary"
                            >
                                {summary}
                            </p>

                            {showChips && (
                                <FilterChips
                                    label={t('jobs.filters')}
                                    selected={status === '' ? [''] : [status]}
                                    onChange={(value) =>
                                        setStatus(value === status ? '' : value)
                                    }
                                    chips={[
                                        {
                                            value: '',
                                            label: t('jobs.all_statuses'),
                                            count: visits.length,
                                        },
                                        ...Object.keys(statusCounts).map(
                                            (value) => ({
                                                value,
                                                label: statusLabels[value],
                                                count: statusCounts[value],
                                            }),
                                        ),
                                    ]}
                                />
                            )}

                            {shown.length === 0 && (
                                <p className="da-card p-10 text-center text-sm text-muted-foreground">
                                    {term
                                        ? t('jobs.search_empty', {
                                              search: term,
                                          })
                                        : tab === 'completed'
                                          ? t('jobs.mine_empty.completed')
                                          : date === today
                                            ? t('jobs.mine_empty.today')
                                            : t('jobs.mine_empty.day', {
                                                  date: time.dateOnly(date),
                                              })}
                                </p>
                            )}

                            <ul className="space-y-3">
                                {both &&
                                    shown.map((visit) => (
                                        <li key={visit.id}>
                                            <Link
                                                href={show(visit.job.id)}
                                                className={cn(
                                                    'da-card block space-y-0.5 p-3',
                                                    visit.id === next?.id &&
                                                        'ring-2 ring-[var(--accent)]',
                                                )}
                                                data-test="compact-job"
                                            >
                                                <span
                                                    className={cn(
                                                        'block text-xs font-semibold',
                                                        tab === 'day' &&
                                                            overdue(visit)
                                                            ? 'text-danger'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {time.shortRange(
                                                        visit.scheduled_start,
                                                        visit.scheduled_end,
                                                    )}
                                                </span>
                                                <span className="block truncate font-semibold">
                                                    {visit.job.customer ??
                                                        `#${visit.job.number}`}
                                                </span>
                                                {shortAddress(
                                                    visit.job.address,
                                                ) && (
                                                    <span className="block truncate text-sm text-muted-foreground">
                                                        {shortAddress(
                                                            visit.job.address,
                                                        )}
                                                    </span>
                                                )}
                                                {visit.job.appliance_types
                                                    .length > 0 && (
                                                    <span className="block truncate text-sm">
                                                        {visit.job.appliance_types.join(
                                                            ', ',
                                                        )}
                                                    </span>
                                                )}
                                            </Link>
                                        </li>
                                    ))}
                                {!both &&
                                    shown.map((visit) => (
                                        <JobCard
                                            key={visit.id}
                                            job={visit.job}
                                            href={show(visit.job.id)}
                                            accent={visit.id === next?.id}
                                            overdue={
                                                tab === 'day' && overdue(visit)
                                            }
                                            action={step(visit)}
                                            onAddAddress={
                                                visit.can_work
                                                    ? () =>
                                                          setAddressFor(
                                                              visit.job.id,
                                                          )
                                                    : undefined
                                            }
                                            below={
                                                <>
                                                    {visit.can_edit && (
                                                        <Link
                                                            href={show(
                                                                visit.job.id,
                                                                {
                                                                    query: {
                                                                        voice_edit: 1,
                                                                    },
                                                                },
                                                            )}
                                                            className="mx-3 mb-2 flex min-h-10 items-center gap-1.5 text-sm font-semibold text-violet-700 underline-offset-4 hover:underline"
                                                            data-test="edit-by-voice"
                                                        >
                                                            <Mic className="size-4" />
                                                            {t(
                                                                'automation.quick_edit.open',
                                                            )}
                                                        </Link>
                                                    )}
                                                    {visit.assignees.length ===
                                                        0 &&
                                                        !auth.company
                                                            ?.works_alone &&
                                                        (canAssign ? (
                                                            <button
                                                                type="button"
                                                                className="mx-3 mb-2 flex min-h-10 items-center gap-1.5 text-sm font-semibold text-amber-700 underline-offset-4 hover:underline"
                                                                onClick={() =>
                                                                    setAssigning(
                                                                        visit,
                                                                    )
                                                                }
                                                                data-test="not-assigned"
                                                            >
                                                                <UserX className="size-4" />
                                                                {t(
                                                                    'jobs.not_assigned',
                                                                )}
                                                            </button>
                                                        ) : (
                                                            <span className="mx-3 mb-2 flex items-center gap-1.5 text-sm font-semibold text-amber-700">
                                                                <UserX className="size-4" />
                                                                {t(
                                                                    'jobs.not_assigned',
                                                                )}
                                                            </span>
                                                        ))}
                                                </>
                                            }
                                            time={
                                                // Visits of the shown day show only the window; one still open from an earlier day shows its date.
                                                tab === 'day' &&
                                                time.ymd(
                                                    visit.scheduled_start,
                                                ) === date
                                                    ? time.shortRange(
                                                          visit.scheduled_start,
                                                          visit.scheduled_end,
                                                      )
                                                    : time.window(
                                                          visit.scheduled_start,
                                                          visit.scheduled_end,
                                                      )
                                            }
                                            extra={
                                                (visit.strict_arrival ||
                                                    visit.job.bring) && (
                                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 pt-0.5">
                                                        {visit.strict_arrival && (
                                                            <StrictBadge />
                                                        )}
                                                        {visit.job.bring && (
                                                            <span
                                                                className={cn(
                                                                    'flex items-center gap-1 font-medium',
                                                                    visit.job
                                                                        .bring
                                                                        .done <
                                                                        visit
                                                                            .job
                                                                            .bring
                                                                            .total
                                                                        ? 'text-amber-700'
                                                                        : 'text-emerald-700',
                                                                )}
                                                            >
                                                                <PackageCheck className="size-3.5" />
                                                                {t(
                                                                    'jobs.bring.title',
                                                                )}
                                                                :{' '}
                                                                {t(
                                                                    'jobs.bring.loaded',
                                                                    {
                                                                        done: visit
                                                                            .job
                                                                            .bring
                                                                            .done,
                                                                        total: visit
                                                                            .job
                                                                            .bring
                                                                            .total,
                                                                    },
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                )
                                            }
                                        />
                                    ))}
                            </ul>

                            {othersOnDay > 0 && (
                                <div className="da-card overflow-hidden">
                                    <ListRow
                                        href={calendar({
                                            query: { view: 'day', date },
                                        })}
                                        leading={
                                            <span className="da-soft inline-flex size-10 items-center justify-center rounded-[12px]">
                                                <CalendarDays className="size-5" />
                                            </span>
                                        }
                                        title={t('jobs.others_on_day', {
                                            count: othersOnDay,
                                        })}
                                        subtitle={t('nav.calendar')}
                                        chevron
                                    />
                                </div>
                            )}
                        </section>
                    )}
                    {showTodo && (
                        <section
                            className="space-y-3"
                            aria-label={t('tasks.panes.todo')}
                            data-test="todo-pane"
                        >
                            <div className="flex items-center gap-2">
                                <h2 className="flex-1 text-sm font-semibold text-[var(--task-text)]">
                                    {t('tasks.panes.todo')}
                                </h2>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setNewTask({ draft: null })}
                                    aria-label={t('tasks.add')}
                                    data-test="todo-add"
                                >
                                    <Plus />
                                </Button>
                            </div>
                            <form
                                className="flex items-center gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    void readQuickTask();
                                }}
                            >
                                <Input
                                    value={quickTask}
                                    maxLength={1000}
                                    placeholder={t('tasks.quick_placeholder')}
                                    aria-label={t('tasks.quick_placeholder')}
                                    onChange={(e) =>
                                        setQuickTask(e.target.value)
                                    }
                                    data-test="todo-quick"
                                />
                                <MicButton
                                    value={quickTask}
                                    onChange={setQuickTask}
                                />
                                {quickTask.trim() !== '' && (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        loading={quickBusy}
                                        data-test="todo-quick-fill"
                                    >
                                        {t('tasks.quick_fill')}
                                    </Button>
                                )}
                            </form>
                            {quickNote && (
                                <p className="text-xs text-muted-foreground">
                                    {quickNote}
                                </p>
                            )}
                            {tasks.length === 0 ? (
                                <p className="da-card p-6 text-center text-sm text-muted-foreground">
                                    {t('tasks.empty')}
                                </p>
                            ) : (
                                <TaskList
                                    tasks={tasks}
                                    onOpen={setEditingTask}
                                    bare
                                />
                            )}
                        </section>
                    )}
                </div>
            </div>

            <TaskSheet
                open={editingTask !== null}
                onOpenChange={(open) => !open && setEditingTask(null)}
                task={editingTask}
            />
            <TaskSheet
                open={newTask !== null}
                onOpenChange={(open) => !open && setNewTask(null)}
                date={date}
                draft={newTask?.draft ?? null}
            />

            {addressFor !== null && (
                <JobAddressSheet
                    open
                    onOpenChange={(open) => !open && setAddressFor(null)}
                    jobId={addressFor}
                    current=""
                    others={[]}
                />
            )}

            {canAssign && assigning && (
                <TechnicianPicker
                    open
                    onOpenChange={(open) => !open && setAssigning(null)}
                    date={time.ymd(assigning.scheduled_start)}
                    window={{
                        start: assigning.start_time,
                        end: assigning.end_time,
                    }}
                    excludeVisitIds={[assigning.id]}
                    onPick={(userId) => {
                        const jobId = assigning.job.id;
                        setAssigning(null);
                        router.put(
                            technician(jobId).url,
                            { user_id: userId },
                            { preserveScroll: true },
                        );
                    }}
                />
            )}
        </>
    );
}

MyJobs.layout = {
    breadcrumbs: [{ title: 'jobs.my_jobs', href: mine() }],
};
