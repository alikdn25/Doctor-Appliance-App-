import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { index as assistant } from '@/routes/assistant';
import { show, store } from '@/routes/app-reviews';

type ReviewRow = {
    id: number;
    created_at: string;
    period_from: string;
    period_to: string;
    overview: string;
    suggestions: number;
    cost: number;
};

/**
 * App review (Owner): choose the period (since the last review by default) and run it — only on this button, never
 * on a schedule — and the list of past reviews.
 */
export default function Reviews({
    reviews,
    periods,
}: {
    reviews: ReviewRow[];
    periods: string[];
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const [period, setPeriod] = useState(periods[0] ?? 'since_last');
    const [running, setRunning] = useState(false);

    const run = () => {
        setRunning(true);
        router.post(
            store().url,
            { period },
            { onFinish: () => setRunning(false) },
        );
    };

    return (
        <>
            <Head title={t('assistant.review.title')} />
            <ScreenHeader
                title={t('assistant.review.title')}
                back={assistant()}
            />
            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-24 sm:p-6">
                <section
                    className="da-card space-y-3 p-4"
                    data-test="review-run"
                >
                    <h2 className="font-semibold">
                        {t('assistant.review.period')}
                    </h2>
                    <div role="radiogroup" className="flex flex-wrap gap-2">
                        {periods.map((value) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={period === value}
                                data-state={period === value ? 'on' : 'off'}
                                className="da-chip da-press min-h-11 rounded-2xl px-3 text-sm font-semibold"
                                onClick={() => setPeriod(value)}
                            >
                                {t(`assistant.review.periods.${value}`)}
                            </button>
                        ))}
                    </div>
                    <Button
                        type="button"
                        size="lg"
                        className="w-full"
                        loading={running}
                        onClick={run}
                        data-test="review-run-button"
                    >
                        <ClipboardCheck /> {t('assistant.review.run')}
                    </Button>
                    {running && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {t('assistant.review.running')}
                        </p>
                    )}
                </section>

                <section className="space-y-2">
                    <h2 className="font-semibold">
                        {t('assistant.review.past')}
                    </h2>
                    {reviews.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('assistant.review.empty')}
                        </p>
                    ) : (
                        <ul className="da-card divide-y">
                            {reviews.map((review) => (
                                <li key={review.id}>
                                    <Link
                                        href={show(review.id)}
                                        className="flex min-h-14 items-center gap-3 px-4 py-3"
                                        data-test="review-row"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-semibold">
                                                {time.date(review.created_at)}
                                            </span>
                                            <span className="block text-sm text-muted-foreground">
                                                {time.date(review.period_from)}{' '}
                                                – {time.date(review.period_to)}{' '}
                                                ·{' '}
                                                {t(
                                                    'assistant.review.suggestions_count',
                                                    {
                                                        count: review.suggestions,
                                                    },
                                                )}{' '}
                                                ·{' '}
                                                {t('assistant.review.cost', {
                                                    cost: review.cost.toFixed(
                                                        2,
                                                    ),
                                                })}
                                            </span>
                                        </span>
                                        <ChevronRight className="size-5 text-muted-foreground" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
