import { Link, usePage } from '@inertiajs/react';
import { ListTodo } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { backlog } from '@/routes/jobs';
import { attentionReasons, reasonColors } from '@/types/job-backlog';

/**
 * Thin bar on every working screen: unfinished jobs by reason, each a colored counter
 * that opens the list filtered by that reason. Jobs waiting weeks for parts are never forgotten.
 */
export function UnfinishedJobsBar() {
    const { unfinishedJobs } = usePage().props;
    const t = useTrans();

    if (!unfinishedJobs) return null;

    const reasons = attentionReasons.filter(
        (reason) => unfinishedJobs.counts[reason] > 0,
    );

    return (
        <nav
            aria-label={t('jobs.backlog.title')}
            className="sticky top-0 z-30 flex min-h-10 items-center gap-0.5 overflow-x-auto border-b bg-muted px-2 text-xs whitespace-nowrap"
        >
            <Link
                href={backlog()}
                className="flex min-h-10 shrink-0 items-center gap-1.5 px-2 font-medium hover:underline"
                aria-label={`${t('jobs.backlog.title')}: ${unfinishedJobs.total}`}
            >
                <ListTodo className="size-4" aria-hidden="true" />
                <span className="hidden sm:inline">
                    {t('jobs.backlog.title')}
                </span>
                <span className="rounded-full bg-background px-1.5 tabular-nums">
                    {unfinishedJobs.total}
                </span>
            </Link>
            {reasons.map((reason) => (
                <Link
                    key={reason}
                    href={backlog({ query: { reason } })}
                    className="flex min-h-10 shrink-0 items-center gap-1 rounded-full px-1 hover:bg-background"
                    aria-label={`${t(`jobs.backlog.reasons.${reason}`)}: ${unfinishedJobs.counts[reason]}`}
                >
                    <span
                        className={cn(
                            'flex size-5 items-center justify-center rounded-full text-[11px] font-semibold tabular-nums',
                            reasonColors[reason],
                        )}
                    >
                        {unfinishedJobs.counts[reason]}
                    </span>
                    <span>{t(`jobs.backlog.short.${reason}`)}</span>
                </Link>
            ))}
        </nav>
    );
}
