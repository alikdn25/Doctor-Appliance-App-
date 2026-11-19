import { Head, Link, router } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { useState } from 'react';
import { AddPartSheet } from '@/components/parts/add-part-sheet';
import { PartRow } from '@/components/parts/part-row';
import type { PartItem, SupplierOption } from '@/components/parts/types';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { useTrans } from '@/lib/i18n';
import { index as suppliersPage } from '@/routes/company/suppliers';
import { index } from '@/routes/parts';
import type { Option } from '@/types';

type Tab = 'order' | 'waiting';

/**
 * Parts (owner's task of November 19): "Order" grouped by supplier (parts without one in their own group, last) and
 * "Waiting" (order date, days waited, more than 7 highlighted), each tab with its count. Rows are edited in place;
 * Bought / Ordered / Arrived move a part and the job's status follows. "+" adds a part to an open job.
 */
export default function PartsPage({
    tab,
    counts,
    parts,
    suppliers,
    jobs,
    canManageSuppliers,
}: {
    tab: Tab;
    counts: Record<Tab, number>;
    parts: PartItem[];
    suppliers: SupplierOption[];
    jobs: Option[];
    canManageSuppliers: boolean;
}) {
    const t = useTrans();
    const [adding, setAdding] = useState(false);

    // Order: one group per supplier (in the order of the settings list), then "No supplier".
    const groups =
        tab === 'order'
            ? [
                  ...suppliers
                      .map((s) => ({
                          key: s.value,
                          title: s.label,
                          address: s.address,
                          items: parts.filter((p) => p.supplier_id === s.value),
                      }))
                      .filter((g) => g.items.length > 0),
                  {
                      key: 'none',
                      title: t('parts.no_supplier'),
                      address: null,
                      items: parts.filter(
                          (p) =>
                              p.supplier_id === null ||
                              !suppliers.some((s) => s.value === p.supplier_id),
                      ),
                  },
              ].filter((g) => g.items.length > 0)
            : [{ key: 'waiting', title: '', address: null, items: parts }];

    return (
        <>
            <Head title={t('parts.title')} />
            <ScreenHeader
                title={t('parts.title')}
                actions={
                    <>
                        {canManageSuppliers && (
                            <Link
                                href={suppliersPage()}
                                className={headerButtonClass}
                                aria-label={t('parts.suppliers.title')}
                                title={t('parts.suppliers.title')}
                            >
                                <Truck className="size-5" />
                            </Link>
                        )}
                        <button
                            type="button"
                            className={headerButtonClass}
                            aria-label={t('parts.add')}
                            title={t('parts.add')}
                            onClick={() => setAdding(true)}
                            data-test="parts-add"
                        >
                            <Plus className="size-5" />
                        </button>
                    </>
                }
            />

            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 pb-24 sm:p-6">
                <SegmentedControl
                    label={t('parts.title')}
                    className="flex w-full [&>button]:flex-1"
                    value={tab}
                    onChange={(next) =>
                        router.get(
                            index({
                                query: next === 'order' ? {} : { tab: next },
                            }).url,
                            {},
                            { preserveScroll: true },
                        )
                    }
                    options={[
                        {
                            value: 'order',
                            label: `${t('parts.tabs.order')} · ${counts.order}`,
                        },
                        {
                            value: 'waiting',
                            label: `${t('parts.tabs.waiting')} · ${counts.waiting}`,
                        },
                    ]}
                />

                {parts.length === 0 && (
                    <p className="da-card p-10 text-center text-sm text-muted-foreground">
                        {t(`parts.empty.${tab}`)}
                    </p>
                )}

                {groups.map((group) => (
                    <section
                        key={group.key}
                        className="da-card overflow-hidden"
                        data-test="parts-group"
                    >
                        {group.title && (
                            <header className="border-b px-4 py-2.5">
                                <h2 className="font-semibold">
                                    {group.title}{' '}
                                    <span className="text-sm font-normal text-muted-foreground">
                                        · {group.items.length}
                                    </span>
                                </h2>
                                {group.address && (
                                    <p className="text-xs text-muted-foreground">
                                        {group.address}
                                    </p>
                                )}
                            </header>
                        )}
                        <ul className="divide-y">
                            {group.items.map((part) => (
                                <PartRow
                                    key={part.id}
                                    part={part}
                                    suppliers={suppliers}
                                />
                            ))}
                        </ul>
                    </section>
                ))}
            </div>

            <AddPartSheet
                open={adding}
                onOpenChange={setAdding}
                jobs={jobs}
                suppliers={suppliers}
            />
        </>
    );
}

PartsPage.layout = {
    breadcrumbs: [{ title: 'parts.title', href: index() }],
};
