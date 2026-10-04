import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { JobList } from '@/components/jobs/job-list';
import type { JobRow } from '@/components/jobs/types';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { backlog } from '@/routes/jobs';
import { statusColor } from '@/lib/job-colors';
import { cn } from '@/lib/utils';
import type { BacklogReason } from '@/types/job-backlog';

type Filters = { search: string; reason: BacklogReason | '' };

const reasons: BacklogReason[] = [
    'overdue',
    'parts_to_order',
    'estimate_to_send',
    'needs_schedule',
    'waiting_for_parts',
    'waiting_for_customer',
    'on_hold',
    'scheduled',
];

export default function JobsBacklog({
    jobs,
    filters,
}: {
    jobs: Paginated<JobRow>;
    filters: Filters;
}) {
    const t = useTrans();
    const { unfinishedJobs } = usePage().props;
    const [search, setSearch] = useState(filters.search);
    const filtered = filters.reason !== '' || filters.search !== '';

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(
            backlog().url,
            { search, reason: filters.reason },
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title={t('jobs.backlog.title')} />
            <div className="space-y-4 p-4">
                <PageHeader
                    title={t('jobs.backlog.title')}
                    description={t('jobs.backlog.hint')}
                />
                <nav
                    aria-label={t('jobs.backlog.title')}
                    className="flex flex-wrap gap-2"
                >
                    {(['', ...reasons] as const).map((reason) => (
                        <Button
                            key={reason}
                            variant={
                                filters.reason === reason
                                    ? 'default'
                                    : 'outline'
                            }
                            className="min-h-11"
                            asChild
                        >
                            <Link
                                href={backlog({
                                    query: { reason, search: filters.search },
                                })}
                                aria-current={
                                    filters.reason === reason
                                        ? 'page'
                                        : undefined
                                }
                            >
                                {t(
                                    reason
                                        ? `jobs.backlog.reasons.${reason}`
                                        : 'jobs.backlog.all',
                                )}
                                <span
                                    className={cn(
                                        'flex min-w-5 items-center justify-center rounded-full px-1 text-xs tabular-nums',
                                        reason
                                            ? statusColor(reason).dot
                                            : 'bg-muted text-foreground',
                                    )}
                                >
                                    {reason
                                        ? (unfinishedJobs?.counts[reason] ?? 0)
                                        : (unfinishedJobs?.total ?? 0)}
                                </span>
                            </Link>
                        </Button>
                    ))}
                </nav>
                <form onSubmit={submit} className="flex gap-2">
                    <Input
                        type="search"
                        className="min-h-11 min-w-0 flex-1"
                        placeholder={t('jobs.search')}
                        aria-label={t('jobs.search')}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        className="min-h-11"
                        aria-label={t('jobs.search')}
                    >
                        <Search className="size-4" />
                    </Button>
                    {filtered && (
                        <Button variant="ghost" className="min-h-11" asChild>
                            <Link href={backlog()}>
                                {t('jobs.clear_filters')}
                            </Link>
                        </Button>
                    )}
                </form>
                <p className="text-sm text-muted-foreground">
                    {t('jobs.count', { count: jobs.total })}
                </p>
                <JobList
                    jobs={jobs.data}
                    empty={t(
                        filtered ? 'jobs.no_results' : 'jobs.backlog.empty',
                    )}
                />
                <PaginationLinks links={jobs.links} />
            </div>
        </>
    );
}
