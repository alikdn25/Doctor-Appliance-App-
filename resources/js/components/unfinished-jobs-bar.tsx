import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, ListTodo } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { backlog } from '@/routes/jobs';

export function UnfinishedJobsBar() {
    const { unfinishedJobs } = usePage().props;
    const t = useTrans();

    if (!unfinishedJobs) return null;

    return (
        <nav
            aria-label={t('jobs.backlog.title')}
            className="sticky top-0 z-30 flex min-h-10 items-center gap-3 border-b bg-muted px-4 text-sm"
        >
            <Link
                href={backlog()}
                className="flex min-h-10 min-w-0 flex-1 items-center gap-2 font-medium hover:underline"
            >
                <ListTodo className="size-4 shrink-0" aria-hidden="true" />
                <span className="truncate">{t('jobs.backlog.title')}</span>
                <span className="rounded-full bg-background px-2 text-xs tabular-nums">
                    {unfinishedJobs.total}
                </span>
                <ChevronRight
                    className="size-3.5 shrink-0"
                    aria-hidden="true"
                />
            </Link>
            {unfinishedJobs.counts.overdue > 0 && (
                <Link
                    href={backlog({ query: { reason: 'overdue' } })}
                    className="flex min-h-10 shrink-0 items-center text-xs font-medium text-red-700 hover:underline dark:text-red-300"
                >
                    {t('jobs.backlog.overdue_count', {
                        count: unfinishedJobs.counts.overdue,
                    })}
                </Link>
            )}
        </nav>
    );
}
