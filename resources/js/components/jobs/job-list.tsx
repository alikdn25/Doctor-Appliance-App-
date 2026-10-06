import { User } from 'lucide-react';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { JobCard } from '@/components/jobs/job-card';
import type { JobRow } from '@/components/jobs/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show } from '@/routes/jobs';

/**
 * Jobs as the cards of the approved My Jobs mockup.
 */
export function JobList({ jobs, empty }: { jobs: JobRow[]; empty: string }) {
    const t = useTrans();
    const time = useCompanyTime();

    if (jobs.length === 0) {
        return (
            <p className="da-card p-10 text-center text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <ul className="space-y-3">
            {jobs.map((job) => (
                <JobCard
                    key={job.id}
                    job={job}
                    href={show(job.id)}
                    time={
                        job.visit
                            ? time.window(
                                  job.visit.scheduled_start,
                                  job.visit.scheduled_end,
                              )
                            : t('jobs.not_scheduled')
                    }
                    extra={
                        <>
                            {job.visit && (
                                <div className="flex items-center gap-1.5 text-muted-foreground">
                                    <User className="size-3.5 shrink-0" />
                                    {job.visit.assignees.length > 0
                                        ? job.visit.assignees.join(', ')
                                        : t('jobs.unassigned')}
                                </div>
                            )}
                            {(job.backlog_reason_label ||
                                job.visit?.strict_arrival ||
                                (job.outcome_label &&
                                    job.outcome !== 'repaired')) && (
                                <div className="flex flex-wrap items-center gap-2 text-xs font-medium">
                                    {job.backlog_reason_label && (
                                        <span
                                            className={
                                                job.backlog_reason === 'overdue'
                                                    ? 'text-red-700 dark:text-red-300'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {job.backlog_reason_label}
                                        </span>
                                    )}
                                    {job.visit?.strict_arrival && (
                                        <StrictBadge />
                                    )}
                                    {job.outcome_label &&
                                        job.outcome !== 'repaired' && (
                                            <span className="text-amber-700 dark:text-amber-400">
                                                {job.outcome_label}
                                            </span>
                                        )}
                                </div>
                            )}
                        </>
                    }
                />
            ))}
        </ul>
    );
}
