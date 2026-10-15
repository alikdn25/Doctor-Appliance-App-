import { Head, Link, router } from '@inertiajs/react';
import { Plus, Receipt } from 'lucide-react';
import { InvoiceDueBadge } from '@/components/billing/invoice-due-badge';
import { useMoney } from '@/components/billing/money';
import type { DocumentRow } from '@/components/billing/types';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterChips } from '@/components/ui/filter-chips';
import { SearchField } from '@/components/ui/search-field';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { index, show, start } from '@/routes/invoices';

type Filters = { search: string; status: string };
type Total = { currency: string; amount: number };

const FILTERS = ['outstanding', 'overdue', 'paid', 'all'] as const;

/**
 * Invoices (UX-REDESIGN §10.1): Outstanding and Overdue totals on top, filter chips, live search, one row per
 * invoice with where it stands ("Due in 5 days", "Overdue 3 days", "Paid").
 */
export default function InvoicesIndex({
    invoices,
    hasInvoices,
    filters,
    outstandingTotals,
    overdueTotals,
}: {
    invoices: Paginated<DocumentRow>;
    hasInvoices: boolean;
    filters: Filters;
    outstandingTotals: Total[];
    overdueTotals: Total[];
}) {
    const t = useTrans();
    const money = useMoney();
    const time = useCompanyTime();

    const apply = (next: Partial<Filters>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(
                    ([, value]) => value !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const sum = (totals: Total[]) =>
        totals.length > 0
            ? totals.map((row) => money(row.amount, row.currency)).join(' + ')
            : money(0);

    const emptyTitle =
        filters.search !== ''
            ? t('invoices.empty')
            : !hasInvoices
              ? t('invoices.first_invoice')
              : filters.status === 'overdue'
                ? t('invoices.empty_overdue')
                : filters.status === 'paid'
                  ? t('invoices.empty_paid')
                  : filters.status === 'outstanding'
                    ? t('invoices.empty_outstanding')
                    : t('invoices.empty');

    return (
        <>
            <Head title={t('invoices.title')} />

            <ScreenHeader
                title={t('invoices.title')}
                actions={
                    <Link
                        href={start()}
                        className={headerButtonClass}
                        aria-label={t('invoices.add')}
                        title={t('invoices.add')}
                        data-test="add-invoice"
                    >
                        <Plus className="size-5" />
                    </Link>
                }
            />

            <div className="mx-auto w-full max-w-3xl space-y-3 p-4 sm:p-6">
                <div className="grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        onClick={() => apply({ status: 'outstanding' })}
                        className="da-card da-press p-3 text-left"
                        data-test="metric-outstanding"
                    >
                        <div className="text-xs font-medium text-muted-foreground">
                            {t('invoices.outstanding')}
                        </div>
                        <div className="text-xl font-bold tabular-nums">
                            {sum(outstandingTotals)}
                        </div>
                    </button>
                    <button
                        type="button"
                        onClick={() => apply({ status: 'overdue' })}
                        className="da-card da-press p-3 text-left"
                        data-test="metric-overdue"
                    >
                        <div className="text-xs font-medium text-muted-foreground">
                            {t('invoices.overdue')}
                        </div>
                        <div
                            className={
                                overdueTotals.length > 0
                                    ? 'text-xl font-bold text-danger tabular-nums'
                                    : 'text-xl font-bold tabular-nums'
                            }
                        >
                            {sum(overdueTotals)}
                        </div>
                    </button>
                </div>

                <SearchField
                    value={filters.search}
                    placeholder={t('invoices.search')}
                    onSearch={(search) => apply({ search })}
                />

                <FilterChips
                    label={t('invoices.filter')}
                    selected={[filters.status]}
                    onChange={(status) => apply({ status })}
                    chips={FILTERS.map((value) => ({
                        value,
                        label: t(`invoices.filters.${value}`),
                        danger: value === 'overdue' && overdueTotals.length > 0,
                    }))}
                />

                {invoices.data.length === 0 ? (
                    <div className="da-card">
                        <EmptyState
                            icon={Receipt}
                            title={emptyTitle}
                            action={
                                hasInvoices && filters.status !== 'all' ? (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            apply({
                                                status: 'all',
                                                search: '',
                                            })
                                        }
                                    >
                                        {t('invoices.show_all')}
                                    </Button>
                                ) : !hasInvoices ? (
                                    <Button asChild>
                                        <Link href={start()}>
                                            <Plus />
                                            {t('invoices.add')}
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    </div>
                ) : (
                    <ul
                        className="da-card divide-y overflow-hidden"
                        data-test="invoice-list"
                    >
                        {invoices.data.map((invoice) => (
                            <li key={invoice.id}>
                                <Link
                                    href={show(invoice.id)}
                                    className="flex min-h-16 items-center gap-3 px-3 py-2.5 hover:bg-black/[0.03]"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex min-w-0 items-center gap-2">
                                            <span className="shrink-0 font-semibold">
                                                {invoice.number}
                                            </span>
                                            <span className="truncate">
                                                {invoice.customer}
                                            </span>
                                        </div>
                                        <div className="truncate text-sm text-muted-foreground">
                                            {invoice.due_on
                                                ? t('invoices.issued_due', {
                                                      issued: time.dateOnly(
                                                          invoice.issued_on,
                                                      ),
                                                      due: time.dateOnly(
                                                          invoice.due_on,
                                                      ),
                                                  })
                                                : time.dateOnly(
                                                      invoice.issued_on,
                                                  )}
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        <span className="font-semibold tabular-nums">
                                            {money(
                                                invoice.balance !== null &&
                                                    invoice.balance > 0 &&
                                                    invoice.status !== 'void'
                                                    ? invoice.balance
                                                    : invoice.total,
                                                invoice.currency,
                                            )}
                                        </span>
                                        <InvoiceDueBadge invoice={invoice} />
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <PaginationLinks links={invoices.links} />
            </div>
        </>
    );
}

InvoicesIndex.layout = {
    breadcrumbs: [{ title: 'invoices.title', href: index() }],
};
