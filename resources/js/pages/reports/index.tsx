import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useMoney } from '@/components/billing/money';
import { PageHeader } from '@/components/page-header';
import { BusinessReport } from '@/components/reports/business-report';
import type { BusinessReportData } from '@/components/reports/business-report';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { expenses, index, receipts } from '@/routes/reports';

type Profit = {
    jobs: number;
    revenue: number;
    cost: number;
    fees: number;
    profit: number;
    margin: number | null;
};
type Rate = { jobs: number; callbacks: number; rate: number | null };

/**
 * Office reports: profit and margin, warranty callback rate, no-charge jobs, exports for the bookkeeper.
 */
export default function Reports({
    from,
    to,
    currency,
    totals,
    byTechnician,
    byAppliance,
    callbacks,
    noCharge,
    business,
}: {
    from: string;
    to: string;
    currency: string;
    totals: Profit;
    business: BusinessReportData;
    byTechnician: (Profit & { name: string })[];
    byAppliance: (Profit & { name: string })[];
    callbacks: {
        total: Rate;
        byTechnician: (Rate & { name: string })[];
        byBrand: (Rate & { name: string })[];
        byAppliance: (Rate & { name: string })[];
    };
    noCharge: {
        count: number;
        loss: number;
        byTechnician: { name: string; count: number; loss: number }[];
    };
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const [period, setPeriod] = useState({ from, to });

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get(index().url, period, { preserveState: true });
    };

    const pct = (value: number | null) => (value === null ? '—' : `${value}%`);

    const profitTable = (
        title: string,
        rows: (Profit & { name: string })[],
    ) => (
        <div className="space-y-1">
            <h3 className="text-sm font-medium">{title}</h3>
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th className="p-2 text-left"></th>
                            <th className="p-2 text-right">
                                {t('reports.jobs')}
                            </th>
                            <th className="p-2 text-right">
                                {t('reports.revenue')}
                            </th>
                            <th className="p-2 text-right">
                                {t('reports.cost')}
                            </th>
                            <th className="p-2 text-right">
                                {t('reports.profit')}
                            </th>
                            <th className="p-2 text-right">
                                {t('reports.margin')}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((r) => (
                            <tr key={r.name}>
                                <td className="p-2">{r.name}</td>
                                <td className="p-2 text-right tabular-nums">
                                    {r.jobs}
                                </td>
                                <td className="p-2 text-right tabular-nums">
                                    {money(r.revenue)}
                                </td>
                                <td className="p-2 text-right tabular-nums">
                                    {money(r.cost + r.fees)}
                                </td>
                                <td className="p-2 text-right tabular-nums">
                                    {money(r.profit)}
                                </td>
                                <td className="p-2 text-right tabular-nums">
                                    {pct(r.margin)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );

    const rateTable = (title: string, rows: (Rate & { name: string })[]) => (
        <div className="space-y-1">
            <h3 className="text-sm font-medium">{title}</h3>
            <ul className="divide-y rounded-lg border text-sm">
                {rows.map((r) => (
                    <li key={r.name} className="flex justify-between gap-2 p-2">
                        <span>{r.name}</span>
                        <span className="tabular-nums">
                            {r.callbacks} / {r.jobs} · {pct(r.rate)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );

    return (
        <>
            <Head title={t('reports.title')} />

            <div className="max-w-4xl space-y-8 p-4">
                <PageHeader
                    title={t('reports.title')}
                    description={t('reports.period_hint')}
                />

                <form
                    onSubmit={apply}
                    className="flex flex-wrap items-end gap-2"
                >
                    <label className="text-sm">
                        {t('reports.from')}
                        <Input
                            type="date"
                            value={period.from}
                            onChange={(e) =>
                                setPeriod({ ...period, from: e.target.value })
                            }
                        />
                    </label>
                    <label className="text-sm">
                        {t('reports.to')}
                        <Input
                            type="date"
                            value={period.to}
                            onChange={(e) =>
                                setPeriod({ ...period, to: e.target.value })
                            }
                        />
                    </label>
                    <Button type="submit">{t('reports.apply')}</Button>
                </form>

                <BusinessReport data={business} />

                <section className="space-y-3">
                    <h2 className="text-base font-medium">
                        {t('reports.profit_title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('reports.profit_currency_hint', { currency })}
                    </p>
                    {totals.jobs === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('reports.empty')}
                        </p>
                    ) : (
                        <>
                            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {(
                                    [
                                        [
                                            'reports.revenue',
                                            money(totals.revenue),
                                        ],
                                        ['reports.cost', money(totals.cost)],
                                        ['reports.fees', money(totals.fees)],
                                        [
                                            'reports.profit',
                                            `${money(totals.profit)} · ${pct(totals.margin)}`,
                                        ],
                                    ] as const
                                ).map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="rounded-lg border p-3"
                                    >
                                        <dt className="text-xs text-muted-foreground">
                                            {t(label)}
                                        </dt>
                                        <dd className="text-lg font-semibold tabular-nums">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {profitTable(
                                t('reports.by_technician'),
                                byTechnician,
                            )}
                            {profitTable(
                                t('reports.by_appliance'),
                                byAppliance,
                            )}
                        </>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-medium">
                        {t('reports.callbacks_title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('reports.callbacks_hint')}
                    </p>
                    <p className="text-sm">
                        {t('reports.callbacks')}: {callbacks.total.callbacks} /{' '}
                        {callbacks.total.jobs} · {pct(callbacks.total.rate)}
                    </p>
                    <div className="grid gap-4 md:grid-cols-3">
                        {rateTable(
                            t('reports.by_technician'),
                            callbacks.byTechnician,
                        )}
                        {rateTable(t('reports.by_brand'), callbacks.byBrand)}
                        {rateTable(
                            t('reports.by_appliance'),
                            callbacks.byAppliance,
                        )}
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-medium">
                        {t('reports.no_charge_title')}
                    </h2>
                    <p className="text-sm">
                        {t('reports.count')}: {noCharge.count} ·{' '}
                        {t('reports.loss')}: {money(noCharge.loss)}
                    </p>
                    <ul className="divide-y rounded-lg border text-sm">
                        {noCharge.byTechnician.map((r) => (
                            <li
                                key={r.name}
                                className="flex justify-between gap-2 p-2"
                            >
                                <span>{r.name}</span>
                                <span className="tabular-nums">
                                    {r.count} · {money(r.loss)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="space-y-2">
                    <h2 className="text-base font-medium">
                        {t('reports.exports')}
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a
                                href={
                                    expenses({
                                        query: {
                                            from: period.from,
                                            to: period.to,
                                        },
                                    }).url
                                }
                            >
                                <Download /> {t('reports.expenses_csv')}
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a
                                href={
                                    receipts({
                                        query: {
                                            from: period.from,
                                            to: period.to,
                                        },
                                    }).url
                                }
                            >
                                <Download /> {t('reports.receipts_zip')}
                            </a>
                        </Button>
                    </div>
                </section>
            </div>
        </>
    );
}

Reports.layout = {
    breadcrumbs: [{ title: 'reports.title', href: index() }],
};
