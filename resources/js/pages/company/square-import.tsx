import { Head, router } from '@inertiajs/react';
import { CheckCircle2, Download, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { cn } from '@/lib/utils';
import { connect as connectProvider } from '@/routes/payment-providers';
import { edit as companySettings } from '@/routes/company/settings';
import {
    discard,
    run,
    start,
    status as statusRoute,
    undo,
} from '@/routes/square-import';

type Import = {
    id: number;
    status: 'scanning' | 'review' | 'importing' | 'done' | 'failed' | 'undone';
    step: string | null;
    progress: { done: number; total: number };
    counts: Record<string, number>;
    error: string | null;
    by: string | null;
    created_at: string | null;
    finished_at: string | null;
    undone_at: string | null;
};
type Match = {
    id: number;
    square: { name: string; phone: string | null; email: string | null };
    customer: { id: number | null; name: string | null; phone: string | null };
    matched_by: 'phone' | 'email' | 'square_id' | null;
    decision: 'merge' | 'skip' | 'new';
};

const DECISIONS = ['merge', 'skip', 'new'] as const;

function Progress({ item }: { item: Import }) {
    const t = useTrans();
    const { done, total } = item.progress;
    const label =
        item.status === 'scanning'
            ? t('square_import.scanning')
            : item.step === 'invoices'
              ? t('square_import.importing_invoices')
              : t('square_import.importing_customers');
    const percent = total > 0 ? Math.round((done / total) * 100) : null;

    return (
        <div
            className="da-card space-y-3 p-4"
            role="status"
            data-test="square-import-progress"
        >
            <p className="font-semibold">{label}</p>
            <div className="da-track h-3 overflow-hidden rounded-full">
                <div
                    className={cn(
                        'h-full rounded-full bg-[#1e8cfd] transition-[width]',
                        percent === null && 'w-1/3 animate-pulse',
                    )}
                    style={
                        percent === null ? undefined : { width: `${percent}%` }
                    }
                />
            </div>
            <p className="text-sm text-muted-foreground">
                {item.status === 'scanning' || total === 0
                    ? t('square_import.progress_unknown', { done })
                    : t('square_import.progress', { done, total })}
            </p>
        </div>
    );
}

/**
 * Import from Square (Owner): read the customers, check the matches (merge / skip / create new, or Merge all), Import
 * runs in the background with progress, and a finished import can be undone with one button. Nothing is sent to
 * customers.
 */
export default function SquareImport({
    connected,
    canConnect,
    account,
    missingScopes,
    checkError,
    current: initial,
    matches,
    history,
}: {
    connected: boolean;
    canConnect: boolean;
    account: string | null;
    missingScopes: string[];
    checkError: string | null;
    current: Import | null;
    matches: Match[];
    history: Import[];
}) {
    const t = useTrans();
    const phone = usePhone();
    const [current, setCurrent] = useState(initial);
    const [decisions, setDecisions] = useState<
        Record<number, Match['decision']>
    >(() => Object.fromEntries(matches.map((m) => [m.id, m.decision])));
    const [busy, setBusy] = useState(false);
    const running =
        current?.status === 'scanning' || current?.status === 'importing';

    useEffect(() => setCurrent(initial), [initial]);

    // While it runs, the screen follows the progress; when it ends, the page reloads with the result.
    useEffect(() => {
        if (!running || !current) return;
        const timer = window.setInterval(() => {
            fetch(statusRoute(current.id).url, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((next: Import | null) => {
                    if (!next) return;
                    setCurrent(next);
                    if (
                        next.status !== 'scanning' &&
                        next.status !== 'importing'
                    ) {
                        router.reload();
                    }
                })
                .catch(() => undefined);
        }, 2000);

        return () => window.clearInterval(timer);
    }, [running, current?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const post = (url: string, data: Record<string, unknown> = {}) => {
        setBusy(true);
        router.post(url, data as never, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };
    const counts = current?.counts ?? {};
    const canStart =
        connected &&
        missingScopes.length === 0 &&
        !checkError &&
        (!current || ['done', 'failed', 'undone'].includes(current.status));

    return (
        <>
            <Head title={t('square_import.title')} />
            <ScreenHeader
                title={t('square_import.title')}
                subtitle={account ?? undefined}
                back={companySettings()}
            />
            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6">
                <p className="text-sm text-muted-foreground">
                    {t('square_import.intro')}
                </p>

                {!connected && (
                    <div
                        className="da-card space-y-3 p-4"
                        role="alert"
                        data-test="square-import-connect"
                    >
                        <p className="text-sm">
                            {t('square_import.not_connected')}
                        </p>
                        {!canConnect && (
                            <p className="text-sm text-muted-foreground">
                                {t('square_import.not_available')}
                            </p>
                        )}
                        {canConnect && (
                            <Button asChild>
                                <a
                                    href={
                                        connectProvider('square', {
                                            query: { for: 'import' },
                                        }).url
                                    }
                                >
                                    {t('square_import.connect')}
                                </a>
                            </Button>
                        )}
                    </div>
                )}
                {checkError && (
                    <p className="da-card p-4 text-sm text-danger" role="alert">
                        {checkError}
                    </p>
                )}
                {connected && missingScopes.length > 0 && (
                    <div
                        className="da-card space-y-3 p-4"
                        data-test="square-import-access"
                    >
                        <p className="text-sm">
                            {t('square_import.needs_access')}
                        </p>
                        <Button asChild>
                            <a
                                href={
                                    connectProvider('square', {
                                        query: { for: 'import' },
                                    }).url
                                }
                            >
                                {t('square_import.allow_access')}
                            </a>
                        </Button>
                    </div>
                )}

                {current && running && <Progress item={current} />}

                {current?.status === 'failed' && (
                    <p
                        className="rounded-2xl border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                        role="alert"
                    >
                        {t('square_import.failed', {
                            error: current.error ?? '',
                        })}
                    </p>
                )}

                {current?.status === 'review' && (
                    <section
                        className="space-y-4"
                        data-test="square-import-review"
                    >
                        <h2 className="text-lg font-semibold">
                            {t('square_import.review_title')}
                        </h2>
                        <dl className="grid grid-cols-2 gap-2">
                            {(
                                [
                                    'new',
                                    'matched',
                                    'linked',
                                    'no_contact',
                                ] as const
                            ).map((key) => (
                                <div key={key} className="da-card p-3">
                                    <dt className="text-xs text-muted-foreground">
                                        {t(`square_import.counts.${key}`)}
                                    </dt>
                                    <dd
                                        className="text-2xl font-semibold"
                                        data-test={`square-count-${key}`}
                                    >
                                        {counts[key] ?? 0}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {matches.length > 0 && (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="font-semibold">
                                        {t('square_import.matches_title')}
                                    </h3>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setDecisions(
                                                Object.fromEntries(
                                                    matches.map((m) => [
                                                        m.id,
                                                        'merge',
                                                    ]),
                                                ),
                                            )
                                        }
                                        data-test="square-merge-all"
                                    >
                                        {t('square_import.merge_all')}
                                    </Button>
                                </div>
                                <ul className="space-y-2">
                                    {matches.map((match) => (
                                        <li
                                            key={match.id}
                                            className="da-card space-y-2 p-3"
                                            data-test="square-match"
                                        >
                                            <p className="text-sm">
                                                <span className="font-semibold">
                                                    {match.square.name}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    {[
                                                        phone(
                                                            match.square.phone,
                                                        ),
                                                        match.square.email,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                → {match.customer.name}
                                                {match.matched_by &&
                                                    ` (${t(`square_import.matched_by.${match.matched_by}`)})`}
                                            </p>
                                            <div
                                                role="radiogroup"
                                                className="grid grid-cols-3 gap-1.5"
                                            >
                                                {DECISIONS.map((decision) => (
                                                    <button
                                                        key={decision}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={
                                                            decisions[
                                                                match.id
                                                            ] === decision
                                                        }
                                                        data-state={
                                                            decisions[
                                                                match.id
                                                            ] === decision
                                                                ? 'on'
                                                                : 'off'
                                                        }
                                                        className="da-chip da-press min-h-11 rounded-xl px-2 text-sm font-semibold"
                                                        onClick={() =>
                                                            setDecisions(
                                                                (d) => ({
                                                                    ...d,
                                                                    [match.id]:
                                                                        decision,
                                                                }),
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            `square_import.decision.${decision}`,
                                                        )}
                                                    </button>
                                                ))}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <StickyActionBar>
                            <div className="grid grid-cols-[2fr_3fr] gap-2">
                                <Button
                                    type="button"
                                    size="lg"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        router.delete(discard(current.id).url)
                                    }
                                >
                                    {t('square_import.discard')}
                                </Button>
                                <Button
                                    type="button"
                                    size="lg"
                                    loading={busy}
                                    onClick={() =>
                                        post(run(current.id).url, { decisions })
                                    }
                                    data-test="square-import-run"
                                >
                                    <Download /> {t('square_import.import')}
                                </Button>
                            </div>
                        </StickyActionBar>
                    </section>
                )}

                {current?.status === 'done' && (
                    <section
                        className="da-card space-y-3 p-4"
                        data-test="square-import-done"
                    >
                        <p className="flex items-center gap-2 font-semibold">
                            <CheckCircle2 className="size-5 text-success" />
                            {t('square_import.done_title')}
                        </p>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            {[
                                'customers_created',
                                'customers_merged',
                                'customers_updated',
                                'customers_skipped',
                                'invoices_created',
                                'invoices_updated',
                            ].map((key) => (
                                <div key={key} className="contents">
                                    <dt className="text-muted-foreground">
                                        {t(`square_import.result.${key}`)}
                                    </dt>
                                    <dd className="text-right font-semibold">
                                        {counts[key] ?? 0}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            disabled={busy}
                            onClick={() =>
                                confirm(t('square_import.undo_confirm')) &&
                                post(undo(current.id).url)
                            }
                            data-test="square-import-undo"
                        >
                            <Undo2 /> {t('square_import.undo')}
                        </Button>
                    </section>
                )}

                {canStart && (
                    <Button
                        type="button"
                        size="lg"
                        className="w-full"
                        loading={busy}
                        onClick={() => post(start().url)}
                        data-test="square-import-start"
                    >
                        <Download />{' '}
                        {current
                            ? t('square_import.restart')
                            : t('square_import.start')}
                    </Button>
                )}

                {history.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">
                            {t('square_import.history')}
                        </h2>
                        <ul className="da-card divide-y text-sm">
                            {history.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-center justify-between gap-3 px-4 py-3"
                                >
                                    <span>
                                        {item.created_at?.slice(0, 10)} ·{' '}
                                        {item.by ?? '—'}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {t(
                                            `square_import.statuses.${item.status}`,
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
