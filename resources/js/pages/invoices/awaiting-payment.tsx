import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { ScreenHeader } from '@/components/screen-header';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { awaiting } from '@/routes/invoices';
import { show as showJob } from '@/routes/jobs';

type Row = {
    id: number;
    job_id: number;
    number: string;
    customer: string | null;
    balance: string;
    days: number;
    state: 'sent' | 'opened' | 'paid';
    opened_at: string | null;
    sent_at: string | null;
};

/**
 * Awaiting payment (owner's task of November 25), from the orange chip of the top strip: sent invoices of jobs
 * waiting for their money, oldest first. Each row: customer, invoice number, amount owed, days since it was sent and
 * Sent / Opened {time} / Paid. A tap opens the job.
 */
export default function AwaitingPayment({
    rows,
    jobs,
}: {
    rows: Row[];
    jobs: number;
}) {
    const t = useTrans();
    const time = useCompanyTime();

    return (
        <>
            <Head title={t('awaiting.title')} />
            <ScreenHeader title={t('awaiting.title')} />

            <div className="mx-auto w-full max-w-3xl space-y-3 p-4 pb-32 sm:p-6">
                <p
                    className="text-sm text-muted-foreground"
                    data-test="awaiting-summary"
                >
                    {t('awaiting.summary', { count: jobs })}
                </p>

                {rows.length === 0 ? (
                    <p className="da-card p-10 text-center text-sm text-muted-foreground">
                        {t('awaiting.empty')}
                    </p>
                ) : (
                    <ul className="da-card divide-y overflow-hidden">
                        {rows.map((row) => (
                            <li key={row.id}>
                                <Link
                                    href={showJob(row.job_id)}
                                    className="flex min-h-16 items-center gap-3 p-3 hover:bg-muted/50"
                                    data-test="awaiting-row"
                                >
                                    <div className="min-w-0 flex-1 space-y-0.5">
                                        <p className="truncate font-semibold">
                                            {row.customer ?? '—'}
                                        </p>
                                        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                            <span>
                                                {t('invoices.number', {
                                                    number: row.number,
                                                })}
                                            </span>
                                            <span>·</span>
                                            <span data-test="awaiting-days">
                                                {t('awaiting.days', {
                                                    count: row.days,
                                                })}
                                            </span>
                                        </p>
                                        <span
                                            data-test="awaiting-state"
                                            data-state={row.state}
                                            className={cn(
                                                'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                row.state === 'paid' &&
                                                    'bg-emerald-100 text-emerald-800',
                                                row.state === 'opened' &&
                                                    'bg-sky-100 text-sky-800',
                                                row.state === 'sent' &&
                                                    'bg-muted text-foreground',
                                            )}
                                        >
                                            {row.state === 'opened' &&
                                            row.opened_at
                                                ? t('awaiting.states.opened', {
                                                      time: time.dateTime(
                                                          row.opened_at,
                                                      ),
                                                  })
                                                : t(
                                                      `awaiting.states.${row.state}`,
                                                  )}
                                        </span>
                                    </div>
                                    <span className="shrink-0 text-right font-semibold text-orange-700 tabular-nums dark:text-orange-300">
                                        {row.balance}
                                    </span>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

AwaitingPayment.layout = {
    breadcrumbs: [{ title: 'awaiting.title', href: awaiting() }],
};
