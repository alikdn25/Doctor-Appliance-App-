import { Head, Link } from '@inertiajs/react';
import { Download, Navigation, Phone } from 'lucide-react';
import { mapsUrl, telUrl } from '@/components/customers/types';
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
}: {
    tab: (typeof tabs)[number];
    visits: MyVisit[];
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const install = useInstallPrompt();

    return (
        <>
            <Head title={t('jobs.my_jobs')} />

            <div className="mx-auto w-full max-w-2xl p-4">
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

                <nav className="mb-4 grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={mine({ query: { tab: name } })}
                            preserveScroll
                            className={cn(
                                'rounded-md px-3 py-2 text-center text-sm font-medium',
                                tab === name
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {t(`jobs.tabs.${name}`)}
                        </Link>
                    ))}
                </nav>

                {visits.length === 0 && (
                    <p className="rounded-lg border p-6 text-center text-sm text-muted-foreground">
                        {t(`jobs.mine_empty.${tab}`)}
                    </p>
                )}

                <ul className="space-y-3">
                    {visits.map((visit) => (
                        <li
                            key={visit.id}
                            className="overflow-hidden rounded-lg border"
                        >
                            <Link
                                href={show(visit.job.id)}
                                className="block space-y-1 p-4 hover:bg-muted/50"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-base font-semibold">
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
                                    <StatusBadge
                                        status={visit.job.status}
                                        label={visit.job.status_label}
                                    />
                                </div>
                                <div className="font-medium">
                                    {visit.job.customer}
                                </div>
                                {visit.job.address && (
                                    <div className="text-sm">
                                        {visit.job.address}
                                    </div>
                                )}
                                <div className="text-sm text-muted-foreground">
                                    {[
                                        `#${visit.job.number}`,
                                        visit.job.job_type_label,
                                        visit.job.appliances.join(', '),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                            </Link>
                            {tab !== 'recent' &&
                                (visit.job.address || visit.job.phone) && (
                                    <div className="grid grid-cols-2 gap-2 border-t p-2">
                                        {visit.job.address && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="lg"
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
