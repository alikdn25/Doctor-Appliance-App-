import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    Car,
    CircleCheck,
    Clock,
    Download,
    MapPin,
    Navigation,
    PackageCheck,
    Phone,
    Play,
} from 'lucide-react';
import { useState } from 'react';
import { formatMoney } from '@/components/billing/money';
import { CustomerAvatar } from '@/components/customers/customer-avatar';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { mapsUrl, telUrl } from '@/components/customers/types';
import InputError from '@/components/input-error';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { StatusBadge } from '@/components/jobs/status-badge';
import type { Visit } from '@/components/jobs/types';
import { openOnPhone } from '@/components/messaging/job-messaging';
import { Button } from '@/components/ui/button';
import { useInstallPrompt } from '@/hooks/use-install-prompt';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { mine, show } from '@/routes/jobs';
import { onMyWay, start } from '@/routes/visits';

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
    };
};

const tabs = ['today', 'upcoming', 'recent'] as const;

export default function MyJobs({
    tab,
    visits,
    cashOnHand = {},
}: {
    tab: (typeof tabs)[number];
    visits: MyVisit[];
    cashOnHand?: Record<string, number>;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const install = useInstallPrompt();
    const { auth } = usePage().props;
    const [error, setError] = useState<string>();

    const act = (url: string) =>
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setError(undefined),
                onError: (errors) =>
                    setError(Object.values(errors)[0] as string),
            },
        );

    // The card's third button follows the visit: On my way → Start → Finish visit.
    const mainAction = (visit: MyVisit) => {
        if (tab === 'recent' || !visit.can_work) return null;
        if (visit.status === 'scheduled') {
            return (
                <Button
                    className="h-12"
                    onClick={() => {
                        act(onMyWay(visit.id).url);
                        if (visit.on_my_way_sms) {
                            openOnPhone(
                                visit.job.id,
                                'on_my_way',
                                visit.on_my_way_sms.to,
                                visit.on_my_way_sms.body,
                            );
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
                    className="h-12"
                    onClick={() => act(start(visit.id).url)}
                >
                    <Play /> {t('jobs.start_short')}
                </Button>
            );
        }
        if (visit.status === 'in_progress') {
            return (
                <Button asChild className="h-12">
                    <Link href={show(visit.job.id, { query: { finish: 1 } })}>
                        <CircleCheck /> {t('jobs.finish_short')}
                    </Link>
                </Button>
            );
        }
        return null;
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
                    className="da-track grid grid-cols-3 gap-1 p-1"
                >
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={mine({ query: { tab: name } })}
                            preserveScroll
                            aria-current={tab === name ? 'page' : undefined}
                            className="da-chip da-press flex min-h-11 items-center justify-center rounded-xl px-3 text-center text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            {t(`jobs.tabs.${name}`)}
                        </Link>
                    ))}
                </nav>

                <InputError message={error} />

                {visits.length === 0 && (
                    <p className="da-card p-10 text-center text-sm text-muted-foreground">
                        {t(`jobs.mine_empty.${tab}`)}
                    </p>
                )}

                <ul className="space-y-3">
                    {visits.map((visit) => {
                        const action = mainAction(visit);
                        const buttons = [
                            tab !== 'recent' && visit.job.address,
                            tab !== 'recent' && visit.job.phone,
                            action,
                        ].filter(Boolean).length;
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
                                    className="flex gap-3 p-4 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                >
                                    <CustomerAvatar
                                        icon={visit.job.customer_icon}
                                        name={visit.job.customer ?? undefined}
                                        size="lg"
                                    />
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-bold text-muted-foreground">
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
                                        <div className="text-[17px] font-bold">
                                            {visit.job.customer}
                                        </div>
                                        <div className="flex items-center gap-1.5 text-sm font-semibold tabular-nums">
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
                                        {visit.job.address && (
                                            <div className="flex items-start gap-1.5 text-sm text-muted-foreground">
                                                <MapPin className="mt-0.5 size-4 shrink-0" />
                                                {visit.job.address}
                                            </div>
                                        )}
                                        <div className="text-sm text-muted-foreground">
                                            {[
                                                visit.job.appliances.join(', '),
                                                visit.job.job_type_label,
                                                visit.job.visit_type !==
                                                'new_diagnosis'
                                                    ? visit.job.visit_type_label
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
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
                                </Link>
                                {buttons > 0 && (
                                    <div
                                        className={cn(
                                            'grid gap-2 px-4 pb-4',
                                            buttons === 3
                                                ? 'grid-cols-[1fr_1fr_1.5fr]'
                                                : buttons === 2
                                                  ? 'grid-cols-2'
                                                  : 'grid-cols-1',
                                        )}
                                    >
                                        {tab !== 'recent' &&
                                            visit.job.address && (
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
                                                        {t('jobs.go')}
                                                    </a>
                                                </Button>
                                            )}
                                        {tab !== 'recent' &&
                                            visit.job.phone && (
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    className="h-12"
                                                >
                                                    <a
                                                        href={telUrl(
                                                            visit.job.phone,
                                                        )}
                                                    >
                                                        <Phone />{' '}
                                                        {t('jobs.call')}
                                                    </a>
                                                </Button>
                                            )}
                                        {action}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </>
    );
}

MyJobs.layout = {
    breadcrumbs: [{ title: 'jobs.my_jobs', href: mine() }],
};
