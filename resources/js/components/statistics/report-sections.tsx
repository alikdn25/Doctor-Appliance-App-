import { usePage } from '@inertiajs/react';
import { ChevronDown, Download } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatMoney, useMoney } from '@/components/billing/money';
import { ProfitSummary } from '@/components/reports/profit-summary';
import type { ProfitData } from '@/components/reports/profit-summary';
import { Button } from '@/components/ui/button';
import { InfoHint } from '@/components/ui/info-hint';
import { useTrans } from '@/lib/i18n';
import { expenses, receipts } from '@/routes/reports';
import { exportMethod as taxReserveCsv } from '@/routes/tax-reserve';

type Profit = {
    jobs: number;
    revenue: number;
    cost: number | null;
    fees: number;
    profit: number | null;
    margin: number | null;
};
type Rate = { jobs: number; callbacks: number; rate: number | null };
type Revenue = {
    currency: string;
    invoices: number;
    revenue: number;
    average: number | null;
};
type RevenueGroup = Revenue & { key: string; name: string };
type Business = {
    totals: Revenue[];
    byBrand: RevenueGroup[];
    byTechnician: RevenueGroup[];
    byJobType: RevenueGroup[];
    conversion: { estimates: number; approved: number; rate: number | null };
};
/** A section that opens on tap, with an (i) for how it is counted. */
export function Fold({
    title,
    hint,
    summary,
    children,
    open = false,
}: {
    title: string;
    hint?: string;
    summary?: string;
    children: ReactNode;
    open?: boolean;
}) {
    const t = useTrans();

    return (
        <details className="da-card group overflow-hidden" open={open}>
            <summary className="flex min-h-14 cursor-pointer list-none items-center gap-3 px-4 py-2 [&::-webkit-details-marker]:hidden">
                <span className="min-w-0 flex-1">
                    <span className="block font-semibold">{title}</span>
                    {summary && (
                        <span className="block truncate text-sm text-muted-foreground">
                            {summary}
                        </span>
                    )}
                </span>
                <ChevronDown className="size-5 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="space-y-3 border-t p-4">
                {hint && (
                    <InfoHint title={t('reports.how_counted')} hint={hint} />
                )}
                {children}
            </div>
        </details>
    );
}

export type ReportsData = {
    business: Business;
    profit: ProfitData;
    byTechnician: (Profit & { name: string })[];
    callbacks: {
        total: Rate;
        byTechnician: (Rate & { name: string })[];
        byBrand: (Rate & { name: string })[];
        byAppliance: (Rate & { name: string })[];
    };
    noCharge: {
        count: number;
        loss: number | null;
        byTechnician: { name: string; count: number; loss: number | null }[];
    };
};

/**
 * The earlier Reports, now at the bottom of Statistics: company profit, revenue by brand, technician and job type,
 * estimate conversion, callbacks, no-charge jobs and the exports. Sources and the average ticket are Statistics
 * blocks, so they are not repeated here.
 */
export function ReportSections({
    reports,
    from,
    to,
    currency,
}: {
    reports: ReportsData;
    from: string;
    to: string;
    currency: string;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const { auth } = usePage().props;
    const locale = auth.company?.locale ?? 'en-US';
    const { business, profit, byTechnician, callbacks, noCharge } = reports;
    const pct = (value: number | null) =>
        value === null ? t('reports.no_data') : `${value}%`;
    const cur = (amount: number, code: string) =>
        formatMoney(amount, code, locale);

    const revenueRows = (rows: RevenueGroup[]) =>
        rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">
                {t('reports.no_data')}
            </p>
        ) : (
            <ul className="divide-y text-sm">
                {rows.map((row) => (
                    <li
                        key={`${row.key}-${row.currency}`}
                        className="flex flex-wrap items-baseline justify-between gap-x-3 py-2"
                    >
                        <span className="min-w-0 font-medium">{row.name}</span>
                        <span className="text-muted-foreground tabular-nums">
                            {[
                                t('reports.invoices_n', {
                                    count: row.invoices,
                                }),
                                cur(row.revenue, row.currency),
                                row.average !== null
                                    ? t('reports.avg', {
                                          amount: cur(
                                              row.average,
                                              row.currency,
                                          ),
                                      })
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </li>
                ))}
            </ul>
        );

    const profitRows = (rows: (Profit & { name: string })[]) =>
        rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">
                {t('reports.no_data')}
            </p>
        ) : (
            <ul className="divide-y text-sm">
                {rows.map((row) => (
                    <li
                        key={row.name}
                        className="flex flex-wrap items-baseline justify-between gap-x-3 py-2"
                    >
                        <span className="min-w-0 font-medium">{row.name}</span>
                        <span className="text-muted-foreground tabular-nums">
                            {[
                                t('reports.jobs_n', { count: row.jobs }),
                                money(row.revenue),
                                row.profit === null
                                    ? t('billing.private_cost')
                                    : t('reports.profit_short', {
                                          amount: money(row.profit),
                                          margin: pct(row.margin),
                                      }),
                            ].join(' · ')}
                        </span>
                    </li>
                ))}
            </ul>
        );

    const rateRows = (title: string, rows: (Rate & { name: string })[]) => (
        <div className="space-y-1">
            <h3 className="text-sm font-medium">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('reports.no_data')}
                </p>
            ) : (
                <ul className="divide-y text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.name}
                            className="flex justify-between gap-2 py-2"
                        >
                            <span>{row.name}</span>
                            <span className="text-muted-foreground tabular-nums">
                                {row.callbacks} / {row.jobs} · {pct(row.rate)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );

    return (
        <>
            <Fold
                title={t('reports.sections.profit')}
                hint={t('reports.period_hint')}
                summary={
                    profit.profit === null ? undefined : money(profit.profit)
                }
            >
                <ProfitSummary
                    data={profit}
                    currency={currency}
                    breakdownOnly
                />
                <p className="text-xs text-muted-foreground">
                    {t('reports.profit_currency_hint', { currency })}
                </p>
            </Fold>

            <Fold
                title={t('reports.by_brand')}
                hint={t('reports.revenue_hint')}
            >
                {revenueRows(business.byBrand)}
            </Fold>

            <Fold
                title={t('reports.by_technician')}
                hint={t('reports.revenue_hint')}
            >
                <h3 className="text-sm font-medium">
                    {t('reports.revenue_basis')}
                </h3>
                {revenueRows(business.byTechnician)}
                <h3 className="pt-2 text-sm font-medium">
                    {t('reports.profit_summary.basis')}
                </h3>
                {profitRows(byTechnician)}
            </Fold>

            <Fold title={t('reports.by_job_type')}>
                {revenueRows(business.byJobType)}
            </Fold>

            <Fold
                title={t('reports.conversion_title')}
                summary={
                    business.conversion.rate === null
                        ? t('reports.no_data')
                        : `${business.conversion.rate}% · ${t('reports.conversion_count', { approved: business.conversion.approved, estimates: t('reports.estimates_n', { count: business.conversion.estimates }) })}`
                }
            >
                <p className="text-sm text-muted-foreground">
                    {t('reports.conversion_count', {
                        approved: business.conversion.approved,
                        estimates: t('reports.estimates_n', {
                            count: business.conversion.estimates,
                        }),
                    })}
                </p>
            </Fold>

            <Fold
                title={t('reports.callbacks_title')}
                hint={t('reports.callbacks_hint')}
                summary={`${callbacks.total.callbacks} / ${callbacks.total.jobs} · ${pct(callbacks.total.rate)}`}
            >
                <div className="grid gap-4 md:grid-cols-3">
                    {rateRows(
                        t('reports.by_technician'),
                        callbacks.byTechnician,
                    )}
                    {rateRows(t('reports.by_brand'), callbacks.byBrand)}
                    {rateRows(t('reports.by_appliance'), callbacks.byAppliance)}
                </div>
            </Fold>

            <Fold
                title={t('reports.no_charge_title')}
                summary={`${noCharge.count} · ${
                    noCharge.loss === null
                        ? t('billing.private_cost')
                        : money(noCharge.loss)
                }`}
            >
                {noCharge.byTechnician.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('reports.no_data')}
                    </p>
                ) : (
                    <ul className="divide-y text-sm">
                        {noCharge.byTechnician.map((row) => (
                            <li
                                key={row.name}
                                className="flex justify-between gap-2 py-2"
                            >
                                <span>{row.name}</span>
                                <span className="text-muted-foreground tabular-nums">
                                    {row.count} ·{' '}
                                    {row.loss === null
                                        ? t('billing.private_cost')
                                        : money(row.loss)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Fold>

            <Fold title={t('reports.exports')}>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" asChild>
                        <a href={expenses({ query: { from, to } }).url}>
                            <Download /> {t('reports.expenses_csv')}
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={receipts({ query: { from, to } }).url}>
                            <Download /> {t('reports.receipts_zip')}
                        </a>
                    </Button>
                    {auth.role?.value === 'owner' && (
                        <Button variant="outline" asChild>
                            <a
                                href={
                                    taxReserveCsv({
                                        query: { year: from.slice(0, 4) },
                                    }).url
                                }
                            >
                                <Download /> {t('reports.tax_reserve_csv')}
                            </a>
                        </Button>
                    )}
                </div>
            </Fold>
        </>
    );
}
