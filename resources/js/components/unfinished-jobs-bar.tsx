import { Link, usePage } from '@inertiajs/react';
import { ListTodo } from 'lucide-react';
import { usePublishedHeight } from '@/hooks/use-published-height';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as jobs } from '@/routes/jobs';

/** Order of the counters: what needs action first; future scheduled work last. */
const REASONS = [
    'overdue',
    'draft',
    'needs_schedule',
    'waiting_for_parts',
    'waiting_for_customer',
    'on_hold',
    'scheduled',
] as const;

/**
 * The persistent strip above the header on every screen (SPEC §6, UX-REDESIGN §5.2): the number of unfinished
 * jobs and a counter per category (zeros hidden; Overdue in red, Drafts in orange). A tap opens the unfinished jobs with that filter.
 */
export function UnfinishedJobsBar() {
    const { unfinishedJobs } = usePage().props;
    const t = useTrans();
    // The header sticks just below this strip instead of sliding under it.
    const bar = usePublishedHeight<HTMLElement>('--app-bar-h');

    if (!unfinishedJobs) return null;

    const counts = unfinishedJobs.counts as Record<string, number>;

    return (
        <nav
            ref={bar}
            aria-label={t('jobs.backlog.title')}
            className="da-unfinished-bar sticky top-0 z-30 flex min-h-11 [scrollbar-width:none] items-center gap-2 overflow-x-auto border-b bg-muted px-3 text-sm whitespace-nowrap md:px-4 [&::-webkit-scrollbar]:hidden"
        >
            <Link
                href={jobs({ query: { tab: 'open' } })}
                className="flex min-h-11 shrink-0 items-center gap-2 font-semibold"
                data-test="unfinished-total"
            >
                <ListTodo className="size-4 shrink-0" aria-hidden="true" />
                {t('jobs.backlog.title')}
                <span className="rounded-full bg-background px-2 text-xs tabular-nums">
                    {unfinishedJobs.total}
                </span>
            </Link>
            {REASONS.filter((reason) => (counts[reason] ?? 0) > 0).map(
                (reason) => (
                    <Link
                        key={reason}
                        href={jobs({ query: { tab: 'open', reason } })}
                        data-test={`unfinished-${reason}`}
                        className={cn(
                            'inline-flex h-8 shrink-0 items-center gap-1 rounded-full bg-background px-3 text-xs font-medium',
                            reason === 'overdue' && 'bg-danger text-white',
                            reason === 'draft' && 'da-draft-chip',
                        )}
                    >
                        {t(`jobs.backlog.reasons.${reason}`)}
                        <span className="tabular-nums">{counts[reason]}</span>
                    </Link>
                ),
            )}
        </nav>
    );
}
