import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Banknote,
    ChevronRight,
    Clock,
    Download,
    MapPin,
    Navigation,
    PackageCheck,
    Phone,
    Plus,
    WashingMachine,
} from 'lucide-react';
import { applianceImageUrl } from '@/components/appliance-image';
import { formatMoney } from '@/components/billing/money';
import { CustomerAvatar } from '@/components/customers/customer-avatar';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { mapsUrl, telUrl } from '@/components/customers/types';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { StatusBadge } from '@/components/jobs/status-badge';
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

    // "Microwave • Not heating", "Multiple • Fridge, Dishwasher", "Installation • Cooktop".
    const applianceLine = (job: MyVisit['job']) => {
        const types = job.appliance_types.join(', ');
        const lead =
            job.picture === 'installation'
                ? job.job_type_label
                : job.appliance_types.length > 1
                  ? t('jobs.mine_multiple')
                  : types;
        const detail =
            job.picture === 'installation' || job.appliance_types.length > 1
                ? types
                : job.problem;
        return [lead, detail].filter(Boolean).join(' • ');
    };

    return (
        <>
            <Head title={t('jobs.my_jobs')} />

            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 sm:p-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {t('jobs.my_jobs')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {time.day(new Date().toISOString())}
                        </p>
                    </div>
                    {install && (
                        <Button
                            variant="outline"
                            onClick={() => void install()}
                        >
                            <Download /> {t('jobs.install.button')}
                        </Button>
                    )}
                </div>

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
                    {t(`jobs.tabs.${tab}`)} ({visits.length})
                </h2>

                {visits.length === 0 && (
                    <p className="da-card p-10 text-center text-sm text-muted-foreground">
                        {t(`jobs.mine_empty.${tab}`)}
                    </p>
                )}

                <ul className="space-y-3">
                    {visits.map((visit) => {
                        const line = applianceLine(visit.job);
                        return (
                            <li
                                key={visit.id}
                                className={cn(
                                    'da-card overflow-hidden',
                                    visit.status === 'in_progress' &&
                                        'ring-2 ring-[#FDBA74]',
                                )}
                            >
                                <Link
                                    href={show(visit.job.id)}
                                    className="flex items-start gap-2.5 p-3 pb-2 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                >
                                    <CustomerAvatar
                                        icon={visit.job.customer_icon}
                                        name={visit.job.customer ?? undefined}
                                        size="lg"
                                    />
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5 text-[13px]">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-[17px] font-bold">
                                                #{visit.job.number}
                                            </span>
                                            <StatusBadge
                                                status={visit.job.status}
                                                label={visit.job.status_label}
                                            />
                                            {visit.strict_arrival && (
                                                <StrictBadge />
                                            )}
                                        </div>
                                        <div className="flex items-center gap-1.5 text-[15px] font-bold whitespace-nowrap tabular-nums">
                                            <Clock className="size-4 text-[#0A6CF5]" />
                                            {tab === 'today'
                                                ? time.timeRange(
                                                      visit.scheduled_start,
                                                      visit.scheduled_end,
                                                  )
                                                : time.window(
                                                      visit.scheduled_start,
                                                      visit.scheduled_end,
                                                  )}
                                        </div>
                                        <div className="truncate text-base font-bold">
                                            {visit.job.customer}
                                        </div>
                                        {visit.job.address && (
                                            <div className="flex items-start gap-1.5 text-muted-foreground">
                                                <MapPin className="mt-0.5 size-3.5 shrink-0" />
                                                <span className="line-clamp-2">
                                                    {visit.job.address}
                                                </span>
                                            </div>
                                        )}
                                        {line !== '' && (
                                            <div className="flex items-start gap-1.5 text-muted-foreground">
                                                <WashingMachine className="mt-0.5 size-3.5 shrink-0" />
                                                <span className="line-clamp-2">
                                                    {line}
                                                </span>
                                            </div>
                                        )}
                                        {visit.job.bring && (
                                            <div
                                                className={cn(
                                                    'flex items-center gap-1 text-sm font-medium',
                                                    visit.job.bring.done <
                                                        visit.job.bring.total
                                                        ? 'text-amber-700'
                                                        : 'text-emerald-700',
                                                )}
                                            >
                                                <PackageCheck className="size-4" />
                                                {t('jobs.bring.title')}:{' '}
                                                {t('jobs.bring.loaded', {
                                                    done: visit.job.bring.done,
                                                    total: visit.job.bring
                                                        .total,
                                                })}
                                            </div>
                                        )}
                                    </div>
                                    {visit.job.picture && (
                                        <img
                                            src={applianceImageUrl(
                                                visit.job.picture,
                                            )}
                                            alt=""
                                            loading="lazy"
                                            className="h-20 w-[76px] shrink-0 self-center object-contain mix-blend-multiply"
                                        />
                                    )}
                                    <ChevronRight
                                        aria-hidden="true"
                                        className="size-4 shrink-0 self-center text-[#8A97A8]"
                                    />
                                </Link>
                                <div className="grid grid-cols-3 gap-2 px-3 pb-3">
                                    {visit.job.address ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="h-12"
                                        >
                                            <a
                                                href={mapsUrl(
                                                    visit.job.address,
                                                )}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <Navigation />{' '}
                                                {t('jobs.navigate')}
                                            </a>
                                        </Button>
                                    ) : (
                                        <span />
                                    )}
                                    {visit.job.phone ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="h-12"
                                        >
                                            <a href={telUrl(visit.job.phone)}>
                                                <Phone /> {t('jobs.call')}
                                            </a>
                                        </Button>
                                    ) : (
                                        <span />
                                    )}
                                    <Button asChild className="h-12">
                                        <Link href={show(visit.job.id)}>
                                            {t('jobs.view_job')} <ArrowRight />
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
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
