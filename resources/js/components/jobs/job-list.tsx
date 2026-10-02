import { Link } from '@inertiajs/react';
import { CalendarClock, ChevronRight, MapPin, User } from 'lucide-react';
import { StrictBadge } from '@/components/jobs/job-outcome';
import { StatusBadge } from '@/components/jobs/status-badge';
import type { JobRow } from '@/components/jobs/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show } from '@/routes/jobs';

/**
 * Jobs as tappable cards (one column on phones, compact rows on desktop).
 */
export function JobList({
    jobs,
    empty,
    showCustomer = true,
}: {
    jobs: JobRow[];
    empty: string;
    showCustomer?: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();

    return (
        <ul className="divide-y rounded-lg border">
            {jobs.map((job) => (
                <li key={job.id}>
                    <Link
                        href={show(job.id)}
                        className="flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-muted/50"
                    >
                        <div className="min-w-0 flex-1 space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    #{job.number}
                                </span>
                                {showCustomer && job.customer && (
                                    <span className="font-medium">
                                        {job.customer}
                                    </span>
                                )}
                                <StatusBadge
                                    status={job.status}
                                    label={job.status_label}
                                />
                                {job.backlog_reason_label && (
                                    <span className={job.backlog_reason === 'overdue' ? 'text-xs font-medium text-red-700 dark:text-red-300' : 'text-xs font-medium text-muted-foreground'}>
                                        {job.backlog_reason_label}
                                    </span>
                                )}
                                {job.visit?.strict_arrival && <StrictBadge />}
                                {job.outcome_label &&
                                    job.outcome !== 'repaired' && (
                                        <span className="text-xs text-amber-700 dark:text-amber-400">
                                            {job.outcome_label}
                                        </span>
                                    )}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {[
                                    job.job_type_label,
                                    job.visit_type !== 'new_diagnosis'
                                        ? job.visit_type_label
                                        : null,
                                    job.appliances.join(', '),
                                    job.brand,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </div>
                            <div className="flex flex-col gap-0.5 text-xs text-muted-foreground lg:flex-row lg:gap-4">
                                <span className="flex items-center gap-1">
                                    <CalendarClock className="size-3 shrink-0" />
                                    {job.visit
                                        ? time.window(
                                              job.visit.scheduled_start,
                                              job.visit.scheduled_end,
                                          )
                                        : t('jobs.not_scheduled')}
                                </span>
                                {job.visit && (
                                    <span className="flex items-center gap-1">
                                        <User className="size-3 shrink-0" />
                                        {job.visit.assignees.length > 0
                                            ? job.visit.assignees.join(', ')
                                            : t('jobs.unassigned')}
                                    </span>
                                )}
                                {job.address && (
                                    <span className="flex min-w-0 items-center gap-1">
                                        <MapPin className="size-3 shrink-0" />
                                        <span className="truncate">
                                            {job.address}
                                        </span>
                                    </span>
                                )}
                            </div>
                        </div>
                        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                    </Link>
                </li>
            ))}
            {jobs.length === 0 && (
                <li className="p-6 text-center text-sm text-muted-foreground">
                    {empty}
                </li>
            )}
        </ul>
    );
}
