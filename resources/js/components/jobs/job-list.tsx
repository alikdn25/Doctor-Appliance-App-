import { StrictBadge } from '@/components/jobs/job-outcome';
import { JobCard } from '@/components/jobs/job-card';
import type { JobRow } from '@/components/jobs/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show } from '@/routes/jobs';

/**
 * Jobs as compact office cards (UX-REDESIGN §7.4): window and status with a red Overdue flag, customer, address,
 * appliance and problem; the assignees' initials and Call on the right. Tapping a card opens the job.
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
        <ul className="space-y-2">
            {jobs.map((job) => {
                const reason =
                    job.backlog_reason && job.backlog_reason !== 'overdue'
                        ? job.backlog_reason_label
                        : null;

                return (
                    <JobCard
                        key={job.id}
                        office
                        job={job}
                        href={show(job.id)}
                        assignees={job.visit?.assignees ?? []}
                        overdue={job.backlog_reason === 'overdue'}
                        time={
                            job.visit
                                ? `${time.day(job.visit.scheduled_start)} · ${time.shortRange(
                                      job.visit.scheduled_start,
                                      job.visit.scheduled_end,
                                  )}`
                                : t('jobs.not_scheduled')
                        }
                        extra={
                            (reason ||
                                job.visit?.strict_arrival ||
                                (job.outcome_label &&
                                    job.outcome !== 'repaired')) && (
                                <div className="flex flex-wrap items-center gap-2 pt-0.5 text-xs font-medium">
                                    {reason && (
                                        <span className="text-muted-foreground">
                                            {reason}
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
                            )
                        }
                    />
                );
            })}
        </ul>
    );
}
