import { Head, router } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    index as reviewsPage,
    suggestion as suggestionRoute,
} from '@/routes/app-reviews';

type Suggestion = {
    id: number;
    importance: 'high' | 'medium' | 'low';
    title: string;
    why: string;
    fix: string;
    status: 'done' | 'not_needed' | 'later' | null;
};

const IMPORTANCE: Record<Suggestion['importance'], string> = {
    high: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100',
    medium: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
    low: 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100',
};

/**
 * One App review: what is inconvenient and why (with the numbers), what to fix by importance, each suggestion with
 * Done / Not needed / Later, and the task for Claude Code in one block to copy. The AI changes nothing by itself.
 */
export default function Review({
    review,
}: {
    review: {
        id: number;
        created_at: string;
        period_from: string;
        period_to: string;
        overview: string;
        claude_task: string;
        cost: number;
        suggestions: Suggestion[];
    };
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const [copied, setCopied] = useState(false);

    const setStatus = (suggestion: Suggestion, status: Suggestion['status']) =>
        router.put(
            suggestionRoute(suggestion.id).url,
            { status: suggestion.status === status ? null : status },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={t('assistant.review.title')} />
            <ScreenHeader
                title={t('assistant.review.title')}
                subtitle={`${time.date(review.period_from)} – ${time.date(review.period_to)}`}
                back={reviewsPage()}
            />
            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-24 sm:p-6">
                <section className="da-card space-y-2 p-4">
                    <p className="text-[15px] leading-snug whitespace-pre-line">
                        {review.overview}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {time.dateTime(review.created_at)} ·{' '}
                        {t('assistant.review.cost', {
                            cost: review.cost.toFixed(2),
                        })}
                    </p>
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">
                        {t('assistant.review.suggestions')}
                    </h2>
                    {review.suggestions.map((s) => (
                        <div
                            key={s.id}
                            className="da-card space-y-2 p-4"
                            data-test="review-suggestion"
                        >
                            <p className="flex items-start gap-2">
                                <span
                                    className={cn(
                                        'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold',
                                        IMPORTANCE[s.importance],
                                    )}
                                >
                                    {t(
                                        `assistant.review.importance.${s.importance}`,
                                    )}
                                </span>
                                <span className="font-semibold">{s.title}</span>
                            </p>
                            <p className="text-sm">
                                <span className="font-semibold">
                                    {t('assistant.review.why')}:
                                </span>{' '}
                                {s.why}
                            </p>
                            <p className="text-sm">
                                <span className="font-semibold">
                                    {t('assistant.review.fix')}:
                                </span>{' '}
                                {s.fix}
                            </p>
                            <div
                                role="radiogroup"
                                className="grid grid-cols-3 gap-1.5"
                            >
                                {(['done', 'not_needed', 'later'] as const).map(
                                    (status) => (
                                        <button
                                            key={status}
                                            type="button"
                                            role="radio"
                                            aria-checked={s.status === status}
                                            data-state={
                                                s.status === status
                                                    ? 'on'
                                                    : 'off'
                                            }
                                            className="da-chip da-press min-h-11 rounded-xl px-2 text-sm font-semibold"
                                            onClick={() => setStatus(s, status)}
                                            data-test={`suggestion-${status}`}
                                        >
                                            {t(
                                                `assistant.review.statuses.${status}`,
                                            )}
                                        </button>
                                    ),
                                )}
                            </div>
                        </div>
                    ))}
                </section>

                {review.claude_task && (
                    <section
                        className="da-card space-y-2 p-4"
                        data-test="review-task"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="font-semibold">
                                {t('assistant.review.claude_task')}
                            </h2>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    void navigator.clipboard
                                        ?.writeText(review.claude_task)
                                        .then(() => setCopied(true))
                                        .catch(() => undefined)
                                }
                            >
                                <Copy />{' '}
                                {copied
                                    ? t('assistant.problems.copied')
                                    : t('assistant.problems.copy')}
                            </Button>
                        </div>
                        <pre className="max-h-96 overflow-auto rounded-xl bg-muted p-3 text-xs leading-snug whitespace-pre-wrap">
                            {review.claude_task}
                        </pre>
                    </section>
                )}
            </div>
        </>
    );
}
