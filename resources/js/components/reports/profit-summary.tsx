import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useMoney } from '@/components/billing/money';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showInvoice } from '@/routes/invoices';

export type ProfitData = {
    jobs: number;
    revenue: number;
    cost: number | null;
    fees: number;
    expenses: number;
    mileage: {
        distance: number;
        amount: number | null;
        unit: 'km' | 'mi';
        rate_set: boolean;
    };
    profit: number | null;
    margin: number | null;
    missing_costs: {
        id: number;
        description: string;
        kind: string;
        invoice_id: number;
        invoice: string;
        job_number: number | null;
    }[];
};

/**
 * Profit of the period: revenue without taxes minus parts and materials, card fees, expenses and mileage,
 * with the margin, and the parts and materials that have no cost yet.
 */
export function ProfitSummary({
    data,
    currency,
}: {
    data: ProfitData;
    currency: string;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const minus = (value: number | null) =>
        value === null ? '—' : value === 0 ? money(0) : `−${money(value)}`;
    const lines: [string, string][] = [
        [t('reports.profit_summary.revenue'), money(data.revenue)],
        [t('reports.profit_summary.parts'), minus(data.cost)],
        [t('reports.profit_summary.fees'), minus(data.fees)],
        [t('reports.profit_summary.expenses'), minus(data.expenses)],
        [
            t('reports.profit_summary.mileage', {
                distance: data.mileage.distance.toFixed(1),
                unit: t(`trips.units.${data.mileage.unit}`),
            }),
            data.mileage.amount === null
                ? t('reports.profit_summary.no_rate')
                : minus(data.mileage.amount),
        ],
    ];

    return (
        <section
            className="space-y-3"
            aria-label={t('reports.profit_summary.title')}
        >
            <div className="grid grid-cols-2 gap-2">
                <div className="da-card p-3">
                    <p className="text-xs text-muted-foreground">
                        {t('reports.profit_summary.title')}
                    </p>
                    <p
                        className={cn(
                            'text-xl font-semibold whitespace-nowrap tabular-nums',
                            data.profit !== null &&
                                data.profit < 0 &&
                                'text-destructive',
                        )}
                    >
                        {data.profit === null ? '—' : money(data.profit)}
                    </p>
                </div>
                <div className="da-card p-3">
                    <p className="text-xs text-muted-foreground">
                        {t('reports.profit_summary.margin')}
                    </p>
                    <p className="text-xl font-semibold tabular-nums">
                        {data.margin === null ? '—' : `${data.margin}%`}
                    </p>
                </div>
            </div>
            {data.profit === null && (
                <p className="text-xs text-muted-foreground">
                    {t('reports.profit_summary.private_hint')}
                </p>
            )}
            <dl className="da-card divide-y text-sm">
                {lines.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        <dt className="min-w-0">{label}</dt>
                        <dd className="shrink-0 tabular-nums">{value}</dd>
                    </div>
                ))}
                <div className="flex items-center justify-between gap-3 px-3 py-2 font-semibold">
                    <dt>
                        {t('reports.profit_summary.title')} ·{' '}
                        {t('reports.profit_summary.jobs', { count: data.jobs })}
                    </dt>
                    <dd className="tabular-nums">
                        {data.profit === null ? '—' : money(data.profit)}
                    </dd>
                </div>
            </dl>

            {data.missing_costs.length > 0 && (
                <div className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                    <p className="flex items-start gap-2 text-sm font-medium">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        {t('reports.profit_summary.missing_title', {
                            count: data.missing_costs.length,
                        })}
                    </p>
                    <ul className="space-y-1 text-sm">
                        {data.missing_costs.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={showInvoice(item.invoice_id)}
                                    className="flex min-h-11 items-center justify-between gap-2 underline-offset-4 hover:underline"
                                >
                                    <span className="min-w-0 truncate">
                                        {t(`billing.kind_letters.${item.kind}`)}{' '}
                                        · {item.description}
                                    </span>
                                    <span className="shrink-0 text-xs">
                                        {item.invoice}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
