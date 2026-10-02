import { Head, Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    Download,
    Navigation,
    PackageCheck,
    Phone,
} from 'lucide-react';
import { formatMoney } from '@/components/billing/money';
import { mapsUrl, telUrl } from '@/components/customers/types';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { StatusBadge } from '@/components/jobs/status-badge';
import type { Visit } from '@/components/jobs/types';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { useInstallPrompt } from '@/hooks/use-install-prompt';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { mine, show } from '@/routes/jobs';

type MyVisit = Visit & {
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

    return (
        <>
            <Head title={t('jobs.my_jobs')} />

            <div className="mx-auto w-full max-w-2xl space-y-5 p-4 sm:p-6">
                <PageHeader
                    title={t('jobs.my_jobs')}
                    actions={
                        install && (
                            <Button
                                variant="outline"
                                onClick={() => void install()}
                            >
                                <Download /> {t('jobs.install.button')}
                            </Button>
                        )
                    }
                />

                {Object.entries(cashOnHand)
                    .filter(([, amount]) => amount !== 0)
                    .map(([currency, amount]) => (
                        <p
                            key={currency}
                            className="mb-3 flex items-center gap-2 rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-100"
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
                    className="grid grid-cols-3 gap-1 rounded-2xl border bg-muted/50 p-1.5"
                >
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={mine({ query: { tab: name } })}
                            preserveScroll
                            aria-current={tab === name ? 'page' : undefined}
                            className={cn(
                                'flex min-h-12 items-center justify-center rounded-xl px-3 py-2 text-center text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                tab === name
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'text-muted-foreground hover:bg-background hover:text-foreground',
                            )}
                        >
                            {t(`jobs.tabs.${name}`)}
                        </Link>
                    ))}
                </nav>

                {visits.length === 0 && (
                    <p className="rounded-3xl border border-dashed bg-card p-10 text-center text-sm text-muted-foreground">
                        {t(`jobs.mine_empty.${tab}`)}
                    </p>
                )}

                <ul className="space-y-4">
                    {visits.map((visit) => (
                        <li
                            key={visit.id}
                            className="overflow-hidden rounded-3xl border bg-card shadow-sm"
                        >
                            <Link
                                href={show(visit.job.id)}
                                className="block space-y-3 p-5 transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-base font-bold text-primary tabular-nums">
                                        {tab === 'today'
                                            ? time.timeRange(
                                                  visit.scheduled_start,
                                                  visit.scheduled_end,
                                              )
                                            : time.window(
                                                  visit.scheduled_start,
                                                  visit.scheduled_end,
                                              )}
                                    </span>
                                    <span className="flex flex-wrap items-center gap-1">
                                        {visit.strict_arrival && (
                                            <StrictBadge />
                                        )}
                                        <StatusBadge
                                            status={visit.job.status}
                                            label={visit.job.status_label}
                                        />
                                    </span>
                                </div>
                                <div className="text-lg font-semibold tracking-tight">
                                    {visit.job.customer}
                                </div>
                                {visit.job.address && (
                                    <div className="text-sm leading-relaxed text-muted-foreground">
                                        {visit.job.address}
                                    </div>
                                )}
                                <div className="text-sm text-muted-foreground">
                                    {[
                                        `#${visit.job.number}`,
                                        visit.job.job_type_label,
                                        visit.job.visit_type !== 'new_diagnosis'
                                            ? visit.job.visit_type_label
                                            : null,
                                        visit.job.appliances.join(', '),
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
                                                ? 'text-amber-700 dark:text-amber-400'
                                                : 'text-emerald-700 dark:text-emerald-400',
                                        )}
                                    >
                                        <PackageCheck className="size-4" />
                                        {t('jobs.bring.title')}:{' '}
                                        {t('jobs.bring.loaded', {
                                            done: visit.job.bring.done,
                                            total: visit.job.bring.total,
                                        })}
                                    </div>
                                )}
                            </Link>
                            {tab !== 'recent' &&
                                (visit.job.address || visit.job.phone) && (
                                    <div
                                        className={cn(
                                            'grid gap-3 border-t bg-muted/20 p-3',
                                            visit.job.address && visit.job.phone
                                                ? 'grid-cols-2'
                                                : 'grid-cols-1',
                                        )}
                                    >
                                        {visit.job.address && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="lg"
                                                className="min-h-12 rounded-xl bg-background"
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
                                        )}
                                        {visit.job.phone && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="lg"
                                                className="min-h-12 rounded-xl bg-background"
                                            >
                                                <a
                                                    href={telUrl(
                                                        visit.job.phone,
                                                    )}
                                                >
                                                    <Phone /> {t('jobs.call')}
                                                </a>
                                            </Button>
                                        )}
                                    </div>
                                )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

MyJobs.layout = {
    breadcrumbs: [{ title: 'jobs.my_jobs', href: mine() }],
};
