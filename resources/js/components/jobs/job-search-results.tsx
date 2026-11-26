import { Link } from '@inertiajs/react';
import type { JobRow } from '@/components/jobs/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/jobs';

const STATUS_CLASS: Record<string, string> = {
    open: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
    completed:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
    paid: 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200',
    cancelled:
        'bg-zinc-200 text-zinc-700 dark:bg-zinc-500/20 dark:text-zinc-300',
};

/**
 * Search results on Jobs and the calendar (owner's task of November 26): open and closed jobs together, open first.
 * Each row: customer, address, appliance, the last visit's day and a coloured status label; deleted ones at the bottom
 * marked Deleted. A tap opens the job.
 */
export function JobSearchResults({ jobs }: { jobs: JobRow[] }) {
    const t = useTrans();
    const time = useCompanyTime();

    if (jobs.length === 0) {
        return (
            <p className="da-card p-10 text-center text-sm text-muted-foreground">
                {t('jobs.no_results')}
            </p>
        );
    }

    return (
        <ul
            className="da-card divide-y overflow-hidden"
            data-test="job-search-results"
        >
            {jobs.map((job) => {
                const appliance =
                    job.appliance_types.join(', ') || job.job_type_label;
                const last = job.last_visit
                    ? t('jobs.search_last_visit', {
                          date:
                              job.last_visit.length === 10
                                  ? time.dateOnly(job.last_visit)
                                  : time.date(job.last_visit),
                      })
                    : t('jobs.search_no_visit');

                return (
                    <li key={job.id}>
                        <Link
                            href={
                                job.deleted
                                    ? index({ query: { tab: 'deleted' } })
                                    : show(job.id)
                            }
                            className="da-press flex items-start gap-3 p-3 text-sm"
                            data-test="job-search-result"
                            data-status={job.search_status}
                        >
                            <div className="min-w-0 flex-1 space-y-0.5">
                                <p className="truncate font-semibold">
                                    {job.customer ?? '—'}
                                </p>
                                {job.address && (
                                    <p className="truncate text-muted-foreground">
                                        {job.address}
                                    </p>
                                )}
                                <p className="truncate text-muted-foreground">
                                    {appliance} · {last} · #{job.number}
                                </p>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-1">
                                {job.search_status && (
                                    <span
                                        className={cn(
                                            'rounded-full px-2.5 py-0.5 text-xs font-bold',
                                            STATUS_CLASS[job.search_status],
                                        )}
                                    >
                                        {job.search_status_label}
                                    </span>
                                )}
                                {job.deleted && (
                                    <span
                                        className="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white"
                                        data-test="job-deleted"
                                    >
                                        {t('jobs.search_deleted')}
                                    </span>
                                )}
                            </div>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
