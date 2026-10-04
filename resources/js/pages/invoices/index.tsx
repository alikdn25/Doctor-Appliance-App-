import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DocumentList } from '@/components/billing/document-list';
import { useMoney } from '@/components/billing/money';
import type { DocumentRow } from '@/components/billing/types';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { index, start } from '@/routes/invoices';
import type { Option } from '@/types';

type Filters = { search: string; status: string };

export default function InvoicesIndex({
    invoices,
    hasInvoices,
    canCreate,
    filters,
    statuses,
    outstandingTotals,
}: {
    invoices: Paginated<DocumentRow>;
    hasInvoices: boolean;
    canCreate: boolean;
    filters: Filters;
    statuses: Option[];
    outstandingTotals: { currency: string; amount: number }[];
}) {
    const t = useTrans();
    const money = useMoney();
    const [search, setSearch] = useState(filters.search);

    const apply = (next: Partial<Filters>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ ...filters, search, ...next }).filter(
                    ([, value]) => value !== '',
                ),
            ),
            { preserveState: true, replace: true },
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        apply({ search });
    };

    return (
        <>
            <Head title={t('invoices.title')} />

            <div className="max-w-3xl p-4">
                <PageHeader
                    title={t('invoices.title')}
                    actions={
                        canCreate && (
                            <Button asChild className="min-h-11">
                                <Link href={start()}>
                                    <Plus />
                                    {t('invoices.add')}
                                </Link>
                            </Button>
                        )
                    }
                    description={t('invoices.outstanding_total', {
                        amount:
                            outstandingTotals.length > 0
                                ? outstandingTotals
                                      .map((row) =>
                                          money(row.amount, row.currency),
                                      )
                                      .join(' + ')
                                : money(0),
                    })}
                />

                <form onSubmit={submit} className="mb-3 flex gap-2">
                    <Input
                        type="search"
                        value={search}
                        placeholder={t('invoices.search')}
                        aria-label={t('common.search')}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        {t('common.search')}
                    </Button>
                </form>

                <NativeSelect
                    className="mb-4 sm:w-60"
                    aria-label={t('invoices.all_statuses')}
                    value={filters.status}
                    onChange={(e) => apply({ status: e.target.value })}
                >
                    <option value="outstanding">
                        {t('invoices.outstanding')}
                    </option>
                    <option value="all">{t('invoices.all_statuses')}</option>
                    {statuses.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>

                {invoices.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            hasInvoices
                                ? 'invoices.empty'
                                : 'invoices.first_invoice',
                        )}
                    </p>
                ) : (
                    <DocumentList documents={invoices.data} showCustomer />
                )}

                <PaginationLinks links={invoices.links} />
            </div>
        </>
    );
}
