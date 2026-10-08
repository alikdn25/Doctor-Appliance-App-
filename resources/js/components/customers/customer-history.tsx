import { Link } from '@inertiajs/react';
import { ChevronRight, Receipt } from 'lucide-react';
import { useMoney } from '@/components/billing/money';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showInvoice } from '@/routes/invoices';
import { show as showJob } from '@/routes/jobs';

export type CustomerSummary = {
    jobs: number;
    money: { currency: string; paid: number; owed: number }[];
};

export type HistoryRow = {
    id: number;
    number: number;
    date: string | null;
    appliances: string[];
    work: string | null;
    status: string;
    status_label: string;
    currency: string | null;
    total: number | null;
    payment: 'paid' | 'partial' | 'unpaid' | null;
    invoice_id: number | null;
};

/** Jobs, paid and owed at a glance, one money line per currency. */
export function CustomerSummaryTiles({
    summary,
}: {
    summary: CustomerSummary;
}) {
    const t = useTrans();
    const money = useMoney();
    const rows =
        summary.money.length > 0
            ? summary.money
            : [{ currency: undefined, paid: 0, owed: 0 }];

    return (
        <dl
            className="grid grid-cols-3 gap-2"
            aria-label={t('customers.summary.title')}
        >
            <div className="da-card min-w-0 px-2.5 py-3">
                <dt className="text-xs text-muted-foreground">
                    {t('customers.summary.jobs')}
                </dt>
                <dd className="text-lg font-semibold tabular-nums">
                    {summary.jobs}
                </dd>
            </div>
            <div className="da-card min-w-0 px-2.5 py-3">
                <dt className="text-xs text-muted-foreground">
                    {t('customers.summary.paid')}
                </dt>
                {rows.map((row) => (
                    <dd
                        key={row.currency ?? ''}
                        className="text-base font-semibold tracking-tight whitespace-nowrap text-emerald-700 tabular-nums sm:text-lg dark:text-emerald-400"
                    >
                        {money(row.paid, row.currency)}
                    </dd>
                ))}
            </div>
            <div className="da-card min-w-0 px-2.5 py-3">
                <dt className="text-xs text-muted-foreground">
                    {t('customers.summary.owed')}
                </dt>
                {rows.map((row) => (
                    <dd
                        key={row.currency ?? ''}
                        className={cn(
                            'text-base font-semibold tracking-tight whitespace-nowrap tabular-nums sm:text-lg',
                            row.owed > 0 && 'text-destructive',
                        )}
                    >
                        {money(row.owed, row.currency)}
                    </dd>
                ))}
            </div>
        </dl>
    );
}

const paymentTone: Record<string, string> = {
    paid: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    partial:
        'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    unpaid: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
};

/** Job history: date, appliance, what was done, amount and payment status, with links to the job and invoice. */
export function CustomerHistory({
    rows,
    empty,
}: {
    rows: HistoryRow[];
    empty: string;
}) {
    const t = useTrans();
    const money = useMoney();
    const time = useCompanyTime();

    if (rows.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <ul className="da-card divide-y overflow-hidden">
            {rows.map((row) => (
                <li key={row.id} className="flex items-stretch">
                    <Link
                        href={showJob(row.id)}
                        className="flex min-h-16 min-w-0 flex-1 items-start gap-2 p-3 hover:bg-muted/40"
                    >
                        <div className="min-w-0 flex-1 space-y-0.5">
                            <div className="flex flex-wrap items-center gap-x-2 text-sm font-medium">
                                <span>
                                    {row.date ? time.dateOnly(row.date) : '—'}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    #{row.number} · {row.status_label}
                                </span>
                            </div>
                            {row.appliances.length > 0 && (
                                <p className="truncate text-sm">
                                    {row.appliances.join(', ')}
                                </p>
                            )}
                            {row.work && (
                                <p className="line-clamp-2 text-xs text-muted-foreground">
                                    {row.work}
                                </p>
                            )}
                        </div>
                        <div className="shrink-0 space-y-1 text-right">
                            {row.total !== null && (
                                <p className="text-sm font-semibold tabular-nums">
                                    {money(
                                        row.total,
                                        row.currency ?? undefined,
                                    )}
                                </p>
                            )}
                            {row.payment && (
                                <span
                                    className={cn(
                                        'inline-block rounded-full px-2 py-0.5 text-xs font-medium',
                                        paymentTone[row.payment],
                                    )}
                                >
                                    {t(
                                        `customers.history.payment.${row.payment}`,
                                    )}
                                </span>
                            )}
                        </div>
                        <ChevronRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    </Link>
                    {row.invoice_id !== null && (
                        <Link
                            href={showInvoice(row.invoice_id)}
                            className="flex w-12 shrink-0 items-center justify-center border-l text-muted-foreground hover:bg-muted/40"
                            aria-label={t('customers.history.open_invoice', {
                                number: row.number,
                            })}
                        >
                            <Receipt className="size-5" />
                        </Link>
                    )}
                </li>
            ))}
        </ul>
    );
}
