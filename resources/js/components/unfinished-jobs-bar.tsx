import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, ListTodo } from 'lucide-react';
import { useState } from 'react';
import { useTrans } from '@/lib/i18n';
import { statusColor } from '@/lib/job-colors';
import { cn } from '@/lib/utils';
import { backlog } from '@/routes/jobs';
import { attentionReasons } from '@/types/job-backlog';

/**
 * Thin bar on every working screen: unfinished jobs as numbers in colored circles, one per reason, no labels.
 * Tapping the bar opens it: bigger circles with their names, each opening the list filtered by that reason.
 */
export function UnfinishedJobsBar() {
    const { unfinishedJobs } = usePage().props;
    const t = useTrans();
    const [open, setOpen] = useState(false);

    if (!unfinishedJobs) return null;

    const reasons = attentionReasons.filter(
        (reason) => unfinishedJobs.counts[reason] > 0,
    );

    return (
        <nav
            aria-label={t('jobs.backlog.title')}
            className="sticky top-0 z-30 border-b bg-muted"
        >
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-controls="unfinished-jobs-panel"
                aria-label={t('jobs.backlog.title')}
                className="flex min-h-10 w-full items-center gap-1.5 px-3"
            >
                <ListTodo className="size-4 shrink-0" aria-hidden="true" />
                {!open &&
                    reasons.map((reason) => (
                        <span
                            key={reason}
                            title={t(`jobs.backlog.reasons.${reason}`)}
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                statusColor(reason).dot,
                            )}
                        >
                            {unfinishedJobs.counts[reason]}
                        </span>
                    ))}
                {open ? (
                    <ChevronUp className="ml-auto size-4" aria-hidden="true" />
                ) : (
                    <ChevronDown
                        className="ml-auto size-4"
                        aria-hidden="true"
                    />
                )}
            </button>
            {open && (
                <div
                    id="unfinished-jobs-panel"
                    className="flex flex-wrap gap-x-2 gap-y-3 px-3 pb-3"
                >
                    {reasons.map((reason) => (
                        <Link
                            key={reason}
                            href={backlog({ query: { reason } })}
                            onClick={() => setOpen(false)}
                            className="flex w-20 flex-col items-center gap-1 text-center text-[11px] leading-tight"
                        >
                            <span
                                className={cn(
                                    'flex size-11 items-center justify-center rounded-full text-base font-semibold tabular-nums',
                                    statusColor(reason).dot,
                                )}
                            >
                                {unfinishedJobs.counts[reason]}
                            </span>
                            {t(`jobs.backlog.reasons.${reason}`)}
                        </Link>
                    ))}
                    <Link
                        href={backlog()}
                        onClick={() => setOpen(false)}
                        className="flex w-20 flex-col items-center gap-1 text-center text-[11px] leading-tight"
                    >
                        <span className="flex size-11 items-center justify-center rounded-full border bg-background">
                            <ListTodo className="size-5" aria-hidden="true" />
                        </span>
                        {t('jobs.backlog.all')}
                    </Link>
                </div>
            )}
        </nav>
    );
}
