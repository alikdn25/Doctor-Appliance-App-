import { Head, router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { useMoney } from '@/components/billing/money';
import { ScreenHeader } from '@/components/screen-header';
import { ReportSections } from '@/components/statistics/report-sections';
import type { ReportsData } from '@/components/statistics/report-sections';
import { Button } from '@/components/ui/button';
import { FilterChips } from '@/components/ui/filter-chips';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { exportMethod as moneyCsv, index } from '@/routes/statistics';

type Named = { key: string; name: string };

type Stats = {
    perDay: {
        total: number;
        weekday: number | null;
        weekend: number | null;
        week: number | null;
        days: number;
    };
    money: {
        methods: { key: string; amount: number }[];
        total: number;
        taxes: { name: string; amount: number }[];
    };
    appliances: (Named & { count: number })[];
    averageTicket: {
        average: number | null;
        jobs: number;
        byAppliance: (Named & { average: number; jobs: number })[];
    };
    brands: { name: string | null; count: number }[];
    sources: (Named & { customers: number; revenue: number })[];
    cities: { name: string | null; jobs: number; average: number | null }[];
    repeat: {
        customers: number;
        repeat: number;
        rate: number | null;
        possibleWarranty: number;
    };
    months: { month: string; amount: number; change: number | null }[];
    diagnostic: {
        diagnostic: number;
        repair: number;
        diagnosticRate: number | null;
        repairRate: number | null;
    };
    timeToComplete: {
        all: number | null;
        withParts: number | null;
        withoutParts: number | null;
        withPartsJobs: number;
        withoutPartsJobs: number;
    };
    profit: { total: number; average: number | null; jobs: number };
    weekdays: { day: number; jobs: number }[];
    unpaid: { count: number; amount: number };
};

const PERIODS = [
    'this_week',
    'this_month',
    'last_month',
    'this_year',
    'last_year',
    'custom',
] as const;

/** A volumetric card with a title; an empty one says "No data for this period". */
function Block({
    title,
    empty,
    action,
    children,
    test,
}: {
    title: string;
    empty?: boolean;
    action?: ReactNode;
    children: ReactNode;
    test?: string;
}) {
    const t = useTrans();

    return (
        <section className="da-card space-y-3 p-4" data-test={test}>
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold">{title}</h2>
                {action}
            </div>
            {empty ? (
                <p className="text-sm text-muted-foreground">
                    {t('statistics.no_data')}
                </p>
            ) : (
                children
            )}
        </section>
    );
}

/** One figure: a label above, the value large. */
function Figure({
    label,
    value,
    caption,
}: {
    label: string;
    value: string;
    caption?: string;
}) {
    return (
        <div className="da-soft rounded-2xl p-3">
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p className="text-xl font-bold whitespace-nowrap tabular-nums">
                {value}
            </p>
            {caption && (
                <p className="text-xs text-muted-foreground">{caption}</p>
            )}
        </div>
    );
}

/** Rows with a bar proportional to the value, largest first. */
function Bars({
    rows,
}: {
    rows: { key: string; label: string; value: number; text: string }[];
}) {
    const max = Math.max(1, ...rows.map((r) => Math.abs(r.value)));

    return (
        <ul className="space-y-2 text-sm">
            {rows.map((row) => (
                <li key={row.key} className="space-y-1">
                    <div className="flex justify-between gap-2">
                        <span className="min-w-0 truncate font-medium">
                            {row.label}
                        </span>
                        <span className="shrink-0 text-muted-foreground tabular-nums">
                            {row.text}
                        </span>
                    </div>
                    <div className="da-track h-2.5 overflow-hidden rounded-full">
                        <div
                            className="h-full rounded-full bg-[linear-gradient(90deg,var(--primary),color-mix(in_srgb,var(--primary)_70%,white))] shadow-[inset_0_1px_0_rgba(255,255,255,.5)]"
                            style={{
                                width: `${(Math.abs(row.value) / max) * 100}%`,
                            }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

/**
 * More → Statistics (owner's task of November 24; the Owner only): the period on top, then one volumetric card per
 * question — jobs per day, money by method (CSV), appliances, average ticket, brands, sources, cities, repeat
 * customers, revenue by month, diagnostic against repair, time to complete, profit per job, busy days, unpaid
 * invoices — and the earlier reports below.
 */
export default function StatisticsPage({
    period,
    from,
    to,
    currency,
    stats,
    reports,
}: {
    period: (typeof PERIODS)[number];
    from: string;
    to: string;
    today: string;
    currency: string;
    stats: Stats;
    reports: ReportsData;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const { auth } = usePage().props;
    const locale = auth.company?.locale ?? 'en-US';
    const [custom, setCustom] = useState({ from, to });

    const go = (query: Record<string, string>) =>
        router.get(index().url, query, {
            preserveScroll: true,
            replace: true,
        });
    const choose = (value: string) =>
        value === 'custom'
            ? go({ period: 'custom', from, to })
            : go({ period: value });

    const number = (value: number | null, digits = 1) =>
        value === null
            ? '—'
            : new Intl.NumberFormat(locale, {
                  maximumFractionDigits: digits,
              }).format(value);
    const pct = (value: number | null) =>
        value === null ? '—' : `${number(value)}%`;
    const dateText = (value: string, options: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(
            new Date(`${value}T12:00:00Z`),
        );
    const range = `${dateText(from, { day: 'numeric', month: 'short', year: 'numeric' })} – ${dateText(to, { day: 'numeric', month: 'short', year: 'numeric' })}`;
    const weekday = (day: number) =>
        // 2024-01-01 was a Monday.
        dateText(`2024-01-0${day}`, { weekday: 'short' });
    const notSet = t('statistics.not_set');
    const maxMonth = Math.max(1, ...stats.months.map((m) => m.amount));

    return (
        <>
            <Head title={t('statistics.title')} />

            <ScreenHeader title={t('statistics.title')} subtitle={range} />

            <div className="mx-auto w-full max-w-4xl space-y-4 p-4 sm:p-6">
                <FilterChips
                    label={t('statistics.period')}
                    chips={PERIODS.map((p) => ({
                        value: p,
                        label: t(`statistics.periods.${p}`),
                    }))}
                    selected={[period]}
                    onChange={choose}
                />
                {period === 'custom' && (
                    <form
                        className="da-card flex flex-wrap items-end gap-2 p-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ period: 'custom', ...custom });
                        }}
                    >
                        <label className="grid gap-1 text-sm font-medium">
                            {t('statistics.from')}
                            <Input
                                type="date"
                                value={custom.from}
                                onChange={(e) =>
                                    setCustom({
                                        ...custom,
                                        from: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-medium">
                            {t('statistics.to')}
                            <Input
                                type="date"
                                value={custom.to}
                                onChange={(e) =>
                                    setCustom({ ...custom, to: e.target.value })
                                }
                            />
                        </label>
                        <Button type="submit">{t('statistics.show')}</Button>
                    </form>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    {/* а) Jobs per day */}
                    <Block
                        title={t('statistics.blocks.per_day')}
                        empty={stats.perDay.total === 0}
                        test="stats-per-day"
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <Figure
                                label={t('statistics.weekdays_avg')}
                                value={number(stats.perDay.weekday)}
                            />
                            <Figure
                                label={t('statistics.weekend_avg')}
                                value={number(stats.perDay.weekend)}
                            />
                            <Figure
                                label={t('statistics.week_avg')}
                                value={number(stats.perDay.week)}
                            />
                            <Figure
                                label={t('statistics.completed_jobs')}
                                value={number(stats.perDay.total, 0)}
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t('statistics.per_day_hint')}
                        </p>
                    </Block>

                    {/* б) Money by payment method */}
                    <Block
                        title={t('statistics.blocks.money')}
                        empty={stats.money.total === 0}
                        test="stats-money"
                        action={
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={
                                        moneyCsv({
                                            query: {
                                                period: 'custom',
                                                from,
                                                to,
                                            },
                                        }).url
                                    }
                                >
                                    <Download /> {t('statistics.csv')}
                                </a>
                            </Button>
                        }
                    >
                        <ul className="divide-y text-sm">
                            {stats.money.methods.map((m) => (
                                <li
                                    key={m.key}
                                    className="flex justify-between gap-2 py-2"
                                >
                                    <span>
                                        {t(`statistics.methods.${m.key}`)}
                                    </span>
                                    <span className="tabular-nums">
                                        {money(m.amount)}
                                    </span>
                                </li>
                            ))}
                            <li className="flex justify-between gap-2 py-2 font-bold">
                                <span>{t('statistics.total')}</span>
                                <span className="tabular-nums">
                                    {money(stats.money.total)}
                                </span>
                            </li>
                            {stats.money.taxes.map((tax) => (
                                <li
                                    key={tax.name}
                                    className="flex justify-between gap-2 py-2 text-muted-foreground"
                                >
                                    <span>
                                        {t('statistics.tax_in', {
                                            name: tax.name,
                                        })}
                                    </span>
                                    <span className="tabular-nums">
                                        {money(tax.amount)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Block>

                    {/* в) Appliances */}
                    <Block
                        title={t('statistics.blocks.appliances')}
                        empty={stats.appliances.length === 0}
                    >
                        <Bars
                            rows={stats.appliances.map((r) => ({
                                key: r.key,
                                label: r.name,
                                value: r.count,
                                text: number(r.count, 0),
                            }))}
                        />
                    </Block>

                    {/* г) Average ticket */}
                    <Block
                        title={t('statistics.blocks.average_ticket')}
                        empty={stats.averageTicket.average === null}
                    >
                        <Figure
                            label={t('statistics.average_all')}
                            value={money(stats.averageTicket.average ?? 0)}
                            caption={t('statistics.jobs_n', {
                                count: stats.averageTicket.jobs,
                            })}
                        />
                        <Bars
                            rows={stats.averageTicket.byAppliance.map((r) => ({
                                key: r.key,
                                label: r.name,
                                value: r.average,
                                text: `${money(r.average)} · ${t('statistics.jobs_n', { count: r.jobs })}`,
                            }))}
                        />
                    </Block>

                    {/* д) Brands */}
                    <Block
                        title={t('statistics.blocks.brands')}
                        empty={stats.brands.length === 0}
                    >
                        <Bars
                            rows={stats.brands.map((r) => ({
                                key: r.name ?? '',
                                label: r.name ?? notSet,
                                value: r.count,
                                text: number(r.count, 0),
                            }))}
                        />
                    </Block>

                    {/* е) Customer sources */}
                    <Block
                        title={t('statistics.blocks.sources')}
                        empty={stats.sources.length === 0}
                    >
                        <ul className="divide-y text-sm">
                            {stats.sources.map((r) => (
                                <li
                                    key={r.key}
                                    className="flex flex-wrap justify-between gap-x-3 py-2"
                                >
                                    <span className="font-medium">
                                        {r.name}
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {t('statistics.new_customers_n', {
                                            count: r.customers,
                                        })}{' '}
                                        · {money(r.revenue)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Block>

                    {/* ж) Cities */}
                    <Block
                        title={t('statistics.blocks.cities')}
                        empty={stats.cities.length === 0}
                    >
                        <ul className="divide-y text-sm">
                            {stats.cities.map((r) => (
                                <li
                                    key={r.name ?? ''}
                                    className="flex flex-wrap justify-between gap-x-3 py-2"
                                >
                                    <span className="font-medium">
                                        {r.name ?? notSet}
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {t('statistics.jobs_n', {
                                            count: r.jobs,
                                        })}
                                        {r.average !== null &&
                                            ` · ${t('statistics.avg', { amount: money(r.average) })}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Block>

                    {/* з) Repeat customers */}
                    <Block
                        title={t('statistics.blocks.repeat')}
                        empty={stats.repeat.customers === 0}
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <Figure
                                label={t('statistics.repeat_share')}
                                value={pct(stats.repeat.rate)}
                                caption={t('statistics.repeat_of', {
                                    repeat: stats.repeat.repeat,
                                    customers: stats.repeat.customers,
                                })}
                            />
                            <Figure
                                label={t('statistics.possible_warranty')}
                                value={number(stats.repeat.possibleWarranty, 0)}
                            />
                        </div>
                    </Block>
                </div>

                {/* и) Revenue by month */}
                <Block
                    title={t('statistics.blocks.months')}
                    empty={stats.months.every((m) => m.amount === 0)}
                    test="stats-months"
                >
                    <div
                        className="-mx-1 flex h-52 items-end gap-1 overflow-x-auto px-1 pb-1"
                        tabIndex={0}
                        role="group"
                        aria-label={t('statistics.blocks.months')}
                    >
                        {stats.months.map((m) => (
                            <div
                                key={m.month}
                                className="flex h-full min-w-12 flex-1 flex-col items-center justify-end gap-1"
                            >
                                <span
                                    className={cn(
                                        'text-[11px] font-semibold tabular-nums',
                                        m.change === null
                                            ? 'text-muted-foreground'
                                            : m.change >= 0
                                              ? 'text-emerald-600'
                                              : 'text-danger',
                                    )}
                                >
                                    {m.change === null
                                        ? '—'
                                        : `${m.change > 0 ? '+' : ''}${number(m.change, 0)}%`}
                                </span>
                                <div
                                    className="w-full rounded-t-lg bg-[linear-gradient(180deg,color-mix(in_srgb,var(--primary)_75%,white),var(--primary))] shadow-[inset_0_1px_0_rgba(255,255,255,.55),0_4px_10px_rgba(0,0,0,.12)]"
                                    style={{
                                        height: `${Math.max(2, (m.amount / maxMonth) * 100)}%`,
                                    }}
                                    title={money(m.amount)}
                                />
                                <span className="text-[11px] text-muted-foreground">
                                    {dateText(m.month, { month: 'short' })}
                                </span>
                            </div>
                        ))}
                    </div>
                </Block>

                <div className="grid gap-4 md:grid-cols-2">
                    {/* к) Diagnostic only against repair */}
                    <Block
                        title={t('statistics.blocks.diagnostic')}
                        empty={
                            stats.diagnostic.diagnostic +
                                stats.diagnostic.repair ===
                            0
                        }
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <Figure
                                label={t('statistics.only_diagnostic')}
                                value={number(stats.diagnostic.diagnostic, 0)}
                                caption={pct(stats.diagnostic.diagnosticRate)}
                            />
                            <Figure
                                label={t('statistics.repair')}
                                value={number(stats.diagnostic.repair, 0)}
                                caption={pct(stats.diagnostic.repairRate)}
                            />
                        </div>
                    </Block>

                    {/* л) Time to complete */}
                    <Block
                        title={t('statistics.blocks.time_to_complete')}
                        empty={stats.timeToComplete.all === null}
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <Figure
                                label={t('statistics.without_parts')}
                                value={t('statistics.days', {
                                    count: number(
                                        stats.timeToComplete.withoutParts,
                                    ),
                                })}
                                caption={t('statistics.jobs_n', {
                                    count: stats.timeToComplete
                                        .withoutPartsJobs,
                                })}
                            />
                            <Figure
                                label={t('statistics.with_parts')}
                                value={t('statistics.days', {
                                    count: number(
                                        stats.timeToComplete.withParts,
                                    ),
                                })}
                                caption={t('statistics.jobs_n', {
                                    count: stats.timeToComplete.withPartsJobs,
                                })}
                            />
                        </div>
                    </Block>

                    {/* м) Profit per job */}
                    <Block
                        title={t('statistics.blocks.profit')}
                        empty={stats.profit.jobs === 0}
                        test="stats-profit"
                    >
                        <div className="grid grid-cols-2 gap-2">
                            <Figure
                                label={t('statistics.profit_average')}
                                value={money(stats.profit.average ?? 0)}
                            />
                            <Figure
                                label={t('statistics.profit_total')}
                                value={money(stats.profit.total)}
                                caption={t('statistics.jobs_n', {
                                    count: stats.profit.jobs,
                                })}
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t('statistics.profit_hint')}
                        </p>
                    </Block>

                    {/* н) Busy days of the week */}
                    <Block
                        title={t('statistics.blocks.weekdays')}
                        empty={stats.weekdays.every((d) => d.jobs === 0)}
                    >
                        <Bars
                            rows={stats.weekdays.map((d) => ({
                                key: String(d.day),
                                label: weekday(d.day),
                                value: d.jobs,
                                text: number(d.jobs, 0),
                            }))}
                        />
                    </Block>

                    {/* о) Unpaid invoices */}
                    <Block
                        title={t('statistics.blocks.unpaid')}
                        empty={stats.unpaid.count === 0}
                    >
                        <Figure
                            label={t('statistics.unpaid_amount')}
                            value={money(stats.unpaid.amount)}
                            caption={t('statistics.invoices_n', {
                                count: stats.unpaid.count,
                            })}
                        />
                    </Block>
                </div>

                <h2 className="pt-2 text-lg font-semibold">
                    {t('statistics.reports')}
                </h2>
                <ReportSections
                    reports={reports}
                    from={from}
                    to={to}
                    currency={currency}
                />
            </div>
        </>
    );
}

StatisticsPage.layout = {
    breadcrumbs: [{ title: 'statistics.title', href: index() }],
};
