import { Head, Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    Download,
    PackageCheck,
    Plus,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { formatMoney } from '@/components/billing/money';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { JobCard, StatusCircles } from '@/components/jobs/job-card';
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

const tabs = ['today', 'upcoming', 'recent'] as const;

/**
 * My jobs, as on the approved mockup: tabs with counts, one card per visit (avatar, number and status,
 * time, name, address, appliance and problem, appliance picture) with Navigate, Call and View job,
 * and Book customer at the end. Visit steps (On my way, Start, Finish) are on the job page.
 */
export default function MyJobs({
    tab,
    counts = { today: 0, upcoming: 0, recent: 0 },
    visits,
    cashOnHand = {},
}: {
    tab: (typeof tabs)[number];
    counts?: Record<(typeof tabs)[number], number>;
    visits: MyVisit[];
    cashOnHand?: Record<string, number>;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const install = useInstallPrompt();
    const { auth } = usePage().props;
    const canBook = auth.can?.createJobs ?? false;
    const [searchOpen, setSearchOpen] = useState(false);
    const [legendOpen, setLegendOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    // Count circles per status of this tab's jobs; tapping one shows only that status.
    const statusCounts: Record<string, number> = {};
    const statusLabels: Record<string, string> = {};
    visits.forEach((visit) => {
        statusCounts[visit.job.status] =
            (statusCounts[visit.job.status] ?? 0) + 1;
        statusLabels[visit.job.status] = visit.job.status_label;
    });
    const term = search.trim().toLowerCase();
    const shown = visits.filter(
        (visit) =>
            (status === '' || visit.job.status === status) &&
            (term === '' ||
                [
                    `#${visit.job.number}`,
                    visit.job.customer,
                    visit.job.address,
                    visit.job.phone,
                    ...visit.job.appliance_types,
                ]
                    .filter(Boolean)
                    .some((value) => value!.toLowerCase().includes(term))),
    );

    return (
        <>
            <Head title={t('jobs.my_jobs')} />

            <ScreenHeader
                title={t('jobs.my_jobs')}
                subtitle={t('jobs.today_subtitle', {
                    date: time.fullDay(new Date().toISOString()),
                })}
                actions={
                    <>
                        <button
                            type="button"
                            className={headerButtonClass}
                            aria-label={t('common.search')}
                            aria-expanded={searchOpen}
                            onClick={() => setSearchOpen(!searchOpen)}
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

                <nav
                    aria-label={t('jobs.my_jobs')}
                    className="grid grid-cols-3 gap-2"
                >
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={mine({ query: { tab: name } })}
                            preserveScroll
                            aria-current={tab === name ? 'page' : undefined}
                            className="da-chip da-press flex min-h-12 items-center justify-center gap-2 rounded-2xl px-2 text-center text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            {t(`jobs.tabs.${name}`)}
                            <span
                                className={cn(
                                    'min-w-6 rounded-full px-1.5 text-xs leading-5 tabular-nums',
                                    tab === name
                                        ? 'bg-white/25'
                                        : 'bg-[#E3EAF4] text-[#334155]',
                                )}
                            >
                                {counts[name]}
                            </span>
                        </Link>
                    ))}
                </nav>

                <h2 className="pt-1 text-lg font-bold">
                    {t(`jobs.tabs.${tab}`)} ({shown.length})
                </h2>

                {shown.length === 0 && (
                    <p className="da-card p-10 text-center text-sm text-muted-foreground">
                        {t(`jobs.mine_empty.${tab}`)}
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
                                tab === 'today'
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
        </>
    );
}

MyJobs.layout = {
    breadcrumbs: [{ title: 'jobs.my_jobs', href: mine() }],
};
