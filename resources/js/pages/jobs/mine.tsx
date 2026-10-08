import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    ChevronLeft,
    ChevronRight,
    Download,
    PackageCheck,
    Plus,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { formatMoney } from '@/components/billing/money';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { JobCard, StatusCircles } from '@/components/jobs/job-card';
import { QuickTripSheet } from '@/components/jobs/quick-trip-sheet';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Input } from '@/components/ui/input';
import type { Visit } from '@/components/jobs/types';
import { Button } from '@/components/ui/button';
import { useInstallPrompt } from '@/hooks/use-install-prompt';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { create as bookCustomer, mine, show } from '@/routes/jobs';

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

/** "2026-10-09" moved by whole days. */
const shiftDay = (ymd: string, by: number) => {
    const date = new Date(`${ymd}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + by);

    return date.toISOString().slice(0, 10);
};

/**
 * My jobs, as on the approved mockup: tabs with counts, one card per visit (avatar, number and status,
 * time, name, address, appliance and problem, appliance picture) with Navigate, Call and View job,
 * and Book customer at the end. Visit steps (On my way, Start, Finish) are on the job page.
 */
export default function MyJobs({
    tab,
    date,
    today,
    search: initialSearch = '',
    visits,
    cashOnHand = {},
}: {
    tab: Tab;
    date: string;
    today: string;
    search?: string;
    visits: MyVisit[];
    cashOnHand?: Record<string, number>;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const install = useInstallPrompt();
    const { auth } = usePage().props;
    const canBook = auth.can?.createJobs ?? false;
    // The search runs on the server over all tabs (and their counts); it stays open while switching tabs.
    const [searchOpen, setSearchOpen] = useState(initialSearch !== '');
    const [legendOpen, setLegendOpen] = useState(false);
    const [search, setSearch] = useState(initialSearch);
    const firstRender = useRef(true);
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = setTimeout(() => {
            router.get(
                mine().url,
                {
                    tab,
                    ...(tab === 'day' && date !== today ? { date } : {}),
                    ...(search.trim() ? { search: search.trim() } : {}),
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 350);
        return () => clearTimeout(timer);
    }, [search]);
    const term = search.trim();
    const [status, setStatus] = useState('');
    const [tripOpen, setTripOpen] = useState(false);
    const dayLabel =
        date === today
            ? t('jobs.tabs.today')
            : new Intl.DateTimeFormat(auth.company?.locale ?? 'en-US', {
                  month: 'short',
                  day: 'numeric',
                  timeZone: 'UTC',
              }).format(new Date(`${date}T00:00:00Z`));
    const dayHref = (day: string) =>
        mine({
            query: {
                tab: 'day',
                ...(day !== today ? { date: day } : {}),
                ...(term ? { search: term } : {}),
            },
        });
    const title = tab === 'day' ? dayLabel : t('jobs.tabs.completed');

    // Count circles per status of this tab's jobs; tapping one shows only that status.
    const statusCounts: Record<string, number> = {};
    const statusLabels: Record<string, string> = {};
    visits.forEach((visit) => {
        statusCounts[visit.job.status] =
            (statusCounts[visit.job.status] ?? 0) + 1;
        statusLabels[visit.job.status] = visit.job.status_label;
    });
    const shown = visits.filter(
        (visit) => status === '' || visit.job.status === status,
    );

    return (
        <>
            <Head title={t('jobs.my_jobs')} />

            <ScreenHeader
                title={t('jobs.my_jobs')}
                subtitle={
                    date === today
                        ? t('jobs.today_subtitle', {
                              date: time.fullDay(new Date().toISOString()),
                          })
                        : time.dateOnly(date)
                }
                actions={
                    <>
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
                            <Search className="size-6" />
                        </button>
                        <button
                            type="button"
                            className={headerButtonClass}
                            aria-label={t('jobs.filters')}
                            aria-expanded={legendOpen}
                            onClick={() => setLegendOpen(!legendOpen)}
                        >
                            <SlidersHorizontal className="size-6" />
                        </button>
                    </>
                }
            />

            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 sm:p-6">
                {install && (
                    <Button variant="outline" onClick={() => void install()}>
                        <Download /> {t('jobs.install.button')}
                    </Button>
                )}

                <StatusCircles
                    counts={statusCounts}
                    labels={statusLabels}
                    selected={status}
                    onSelect={setStatus}
                    open={legendOpen}
                    onOpenChange={setLegendOpen}
                />

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

                {/* One day at a time (arrows move the day), the work completed lately, and + Trip. */}
                <nav aria-label={t('jobs.my_jobs')} className="flex gap-2">
                    <div
                        data-state={tab === 'day' ? 'on' : 'off'}
                        className="da-chip flex min-h-12 min-w-0 flex-[1.4] items-center rounded-2xl"
                    >
                        <Link
                            href={dayHref(shiftDay(date, -1))}
                            preserveScroll
                            aria-label={t('jobs.tabs.previous_day')}
                            className="flex h-12 w-10 shrink-0 items-center justify-center rounded-l-2xl"
                        >
                            <ChevronLeft className="size-5" />
                        </Link>
                        <Link
                            href={dayHref(date)}
                            preserveScroll
                            aria-current={tab === 'day' ? 'page' : undefined}
                            className="flex h-12 min-w-0 flex-1 items-center justify-center text-sm font-semibold whitespace-nowrap"
                        >
                            {dayLabel}
                        </Link>
                        <Link
                            href={dayHref(shiftDay(date, 1))}
                            preserveScroll
                            aria-label={t('jobs.tabs.next_day')}
                            className="flex h-12 w-10 shrink-0 items-center justify-center rounded-r-2xl"
                        >
                            <ChevronRight className="size-5" />
                        </Link>
                    </div>
                    <Link
                        href={mine({
                            query: term
                                ? { tab: 'completed', search: term }
                                : { tab: 'completed' },
                        })}
                        preserveState
                        preserveScroll
                        aria-current={tab === 'completed' ? 'page' : undefined}
                        className="da-chip da-press flex min-h-12 min-w-0 flex-1 items-center justify-center rounded-2xl px-2 text-sm font-semibold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        {t('jobs.tabs.completed')}
                    </Link>
                    <button
                        type="button"
                        className="da-trip da-press flex min-h-12 shrink-0 items-center justify-center rounded-2xl px-3 text-sm font-semibold whitespace-nowrap"
                        onClick={() => setTripOpen(true)}
                    >
                        {t('jobs.tabs.trip')}
                    </button>
                </nav>

                <h2 className="pt-1 text-lg font-bold">
                    {title} ({shown.length})
                </h2>

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
                            highlight={visit.status === 'in_progress'}
                            time={
                                tab === 'day' &&
                                time.isToday(visit.scheduled_start)
                                    ? time.timeRange(
                                          visit.scheduled_start,
                                          visit.scheduled_end,
                                      )
                                    : time.window(
                                          visit.scheduled_start,
                                          visit.scheduled_end,
                                      )
                            }
                            extra={
                                <>
                                    {visit.strict_arrival && <StrictBadge />}
                                    {visit.job.bring && (
                                        <div
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
                                                total: visit.job.bring.total,
                                            })}
                                        </div>
                                    )}
                                </>
                            }
                        />
                    ))}
                </ul>

                {canBook && (
                    <Button asChild className="h-14 w-full text-lg">
                        <Link href={bookCustomer({ query: { book: 1 } })}>
                            <Plus className="size-6" /> {t('nav.book_customer')}
                        </Link>
                    </Button>
                )}
            </div>

            <QuickTripSheet open={tripOpen} onOpenChange={setTripOpen} />
        </>
    );
}

MyJobs.layout = {
    breadcrumbs: [{ title: 'jobs.my_jobs', href: mine() }],
};
