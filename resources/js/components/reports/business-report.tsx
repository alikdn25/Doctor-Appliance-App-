import { usePage } from '@inertiajs/react';
import { formatMoney } from '@/components/billing/money';
import { useTrans } from '@/lib/i18n';

type Revenue = {
    currency: string;
    invoices: number;
    revenue: number;
    average: number | null;
};
type RevenueGroup = Revenue & { key: string; name: string };

export type BusinessReportData = {
    totals: Revenue[];
    byBrand: RevenueGroup[];
    byTechnician: RevenueGroup[];
    byJobType: RevenueGroup[];
    bySource: RevenueGroup[];
    conversion: { estimates: number; approved: number; rate: number | null };
};

export function BusinessReport({ data }: { data: BusinessReportData }) {
    const t = useTrans();
    const { auth } = usePage().props;
    const money = (amount: number, currency: string) =>
        formatMoney(amount, currency, auth.company?.locale);
    const table = (title: string, rows: RevenueGroup[]) => (
        <div className="space-y-2">
            <h3 className="text-sm font-medium">{title}</h3>
            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            <th className="p-3 text-left" scope="col">
                                {t('reports.group')}
                            </th>
                            <th className="p-3 text-right" scope="col">
                                {t('reports.invoice_count')}
                            </th>
                            <th className="p-3 text-right" scope="col">
                                {t('reports.revenue')}
                            </th>
                            <th className="p-3 text-right" scope="col">
                                {t('reports.average_ticket')}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((row) => (
                            <tr key={row.key}>
                                <th
                                    className="p-3 text-left font-normal"
                                    scope="row"
                                >
                                    {row.name}
                                </th>
                                <td className="p-3 text-right tabular-nums">
                                    {row.invoices}
                                </td>
                                <td className="p-3 text-right whitespace-nowrap tabular-nums">
                                    {money(row.revenue, row.currency)}
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        {row.currency}
                                    </span>
                                </td>
                                <td className="p-3 text-right whitespace-nowrap tabular-nums">
                                    {row.average === null
                                        ? '—'
                                        : money(row.average, row.currency)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );

    return (
        <>
            <section className="space-y-4">
                <h2 className="text-base font-medium">
                    {t('reports.revenue_title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('reports.revenue_hint')}
                </p>
                {data.totals.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('reports.no_invoices')}
                    </p>
                ) : (
                    <>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            {data.totals.map((total) => (
                                <div
                                    key={total.currency}
                                    className="rounded-2xl border bg-card p-4"
                                >
                                    <dt className="text-sm text-muted-foreground">
                                        {t('reports.revenue')} ·{' '}
                                        {total.currency}
                                    </dt>
                                    <dd className="text-xl font-semibold tabular-nums">
                                        {money(total.revenue, total.currency)}
                                    </dd>
                                    <dt className="mt-2 text-xs text-muted-foreground">
                                        {t('reports.average_ticket')}
                                    </dt>
                                    <dd className="text-sm tabular-nums">
                                        {total.average === null
                                            ? '—'
                                            : money(
                                                  total.average,
                                                  total.currency,
                                              )}
                                        {' · '}
                                        {total.invoices}{' '}
                                        {t('reports.invoice_count')}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {table(t('reports.by_brand'), data.byBrand)}
                        {table(t('reports.by_technician'), data.byTechnician)}
                        {table(t('reports.by_job_type'), data.byJobType)}
                        {table(t('reports.by_source'), data.bySource)}
                    </>
                )}
            </section>
            <section className="space-y-3">
                <h2 className="text-base font-medium">
                    {t('reports.conversion_title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('reports.conversion_hint')}
                </p>
                <p className="text-lg font-semibold tabular-nums">
                    {data.conversion.rate === null
                        ? '—'
                        : `${data.conversion.rate}%`}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('reports.conversion_count', {
                        approved: data.conversion.approved,
                        estimates: data.conversion.estimates,
                    })}
                </p>
            </section>
        </>
    );
}
