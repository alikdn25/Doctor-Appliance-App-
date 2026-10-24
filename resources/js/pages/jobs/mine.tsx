import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
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
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { formatMoney } from '@/components/billing/money';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { InboxHeaderButton } from '@/components/inbox-header-button';
import { cardButton, JobCard } from '@/components/jobs/job-card';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { TechnicianPicker } from '@/components/jobs/technician-picker';
import { TaskList } from '@/components/tasks/task-list';
import { TaskSheet } from '@/components/tasks/task-sheet';
import type { TaskItem } from '@/components/tasks/types';
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
import { useTrans } from '@/lib/i18n';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { calendar } from '@/routes';
import { mine, show, technician } from '@/routes/jobs';
import { finishScreen, onMyWay, start } from '@/routes/visits';

type MyVisit = Visit & {
    can_work?: boolean;
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
    };
};

type Tab = 'day' | 'completed';

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
    cashOnHand = {},
    othersOnDay = 0,
    tasks = [],
}: {
    tab: Tab;
    tasks?: TaskItem[];
    othersOnDay?: number;
    date: string;
    today: string;
    search?: string;
    visits: MyVisit[];
    cashOnHand?: Record<string, number>;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const install = useInstallPrompt();
    const [editingTask, setEditingTask] = useState<TaskItem | null>(null);
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
        if (!visit.can_work || tab !== 'day') {
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
                        // From the technician's phone: the messages app opens with the text ready.
                        const sms = visit.on_my_way_sms;
                        act(
                            visit,
                            onMyWay(visit.id).url,
                            sms ? { phone_sms: sms } : {},
                        );
                        if (sms) {
                            openSmsApp(sms.to, sms.body);
                        }
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

        if (visit.status === 'in_progress') {
            return (
                <Button {...props} asChild loading={false}>
                    <Link href={finishScreen(visit.id)}>
                        <CheckCircle2 /> {t('jobs.actions.complete')}
                    </Link>
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

            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 pb-24 sm:p-6">
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

                {Object.entries(cashOnHand)
                    .filter(([, amount]) => amount !== 0)
                    .map(([currency, amount]) => (
                        <p
                            key={currency}
                            className="flex items-center gap-2 rounded-2xl bg-[linear-gradient(90deg,#FFF8DB,#FCD670)] p-3 text-sm font-semibold text-[#78350F] shadow-[inset_0_1px_0_rgba(255,255,255,.7),0_5px_12px_rgba(180,120,0,.22)]"
                        >
                            <Banknote className="size-4" />
                            {t('cash.my_cash', {
                                amount: formatMoney(
                                    amount,
                                    currency,
                                    auth.company?.locale,
                                ),
                            })}
                        </p>
                    ))}

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
                            ...Object.keys(statusCounts).map((value) => ({
                                value,
                                label: statusLabels[value],
                                count: statusCounts[value],
                            })),
                        ]}
                    />
                )}

                <TaskList tasks={tasks} onOpen={setEditingTask} />

                {shown.length === 0 && (
                    <p className="da-card p-10 text-center text-sm text-muted-foreground">
                        {term
                            ? t('jobs.search_empty', { search: term })
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
                    {shown.map((visit) => (
                        <JobCard
                            key={visit.id}
                            job={visit.job}
                            href={show(visit.job.id)}
                            accent={visit.id === next?.id}
                            overdue={tab === 'day' && overdue(visit)}
                            action={step(visit)}
                            below={
                                visit.assignees.length === 0 &&
                                !auth.company?.works_alone &&
                                (canAssign ? (
                                    <button
                                        type="button"
                                        className="mx-3 mb-2 flex min-h-10 items-center gap-1.5 text-sm font-semibold text-amber-700 underline-offset-4 hover:underline"
                                        onClick={() => setAssigning(visit)}
                                        data-test="not-assigned"
                                    >
                                        <UserX className="size-4" />
                                        {t('jobs.not_assigned')}
                                    </button>
                                ) : (
                                    <span className="mx-3 mb-2 flex items-center gap-1.5 text-sm font-semibold text-amber-700">
                                        <UserX className="size-4" />
                                        {t('jobs.not_assigned')}
                                    </span>
                                ))
                            }
                            time={
                                // Visits of the shown day show only the window; one still open from an earlier day shows its date.
                                tab === 'day' &&
                                time.ymd(visit.scheduled_start) === date
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
                                (visit.strict_arrival || visit.job.bring) && (
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 pt-0.5">
                                        {visit.strict_arrival && (
                                            <StrictBadge />
                                        )}
                                        {visit.job.bring && (
                                            <span
                                                className={cn(
                                                    'flex items-center gap-1 font-medium',
                                                    visit.job.bring.done <
                                                        visit.job.bring.total
                                                        ? 'text-amber-700'
                                                        : 'text-emerald-700',
                                                )}
                                            >
                                                <PackageCheck className="size-3.5" />
                                                {t('jobs.bring.title')}:{' '}
                                                {t('jobs.bring.loaded', {
                                                    done: visit.job.bring.done,
                                                    total: visit.job.bring
                                                        .total,
                                                })}
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
                            href={calendar({ query: { view: 'day', date } })}
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
            </div>

            <TaskSheet
                open={editingTask !== null}
                onOpenChange={(open) => !open && setEditingTask(null)}
                task={editingTask}
            />

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
