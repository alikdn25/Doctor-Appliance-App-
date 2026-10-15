import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { formatMoney } from '@/components/billing/money';
import { CustomerAvatar } from '@/components/customers/customer-avatar';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterChips } from '@/components/ui/filter-chips';
import { SearchField } from '@/components/ui/search-field';
import { usePage } from '@inertiajs/react';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { create, index, show } from '@/routes/customers';
import type { Option } from '@/types';

type CustomerRow = {
    id: number;
    display_name: string;
    avatar_icon: AvatarIcon;
    type: string;
    type_label: string;
    phone: string | null;
    address: string | null;
    tags: string[];
    owes?: { currency: string; amount: number }[];
};

type Filters = { search: string; type: string; tag: string };

/**
 * Customers (UX-REDESIGN §8.1): live search, type and tag chips, one row per customer (avatar, name and tags,
 * phone, address on one line, "Owes $X" in red on the right), and + in the header.
 */
export default function CustomersIndex({
    customers,
    filters,
    types,
    tags,
}: {
    customers: Paginated<CustomerRow>;
    filters: Filters;
    types: Option[];
    tags: string[];
}) {
    const t = useTrans();
    const phoneText = usePhone();
    const { auth } = usePage().props;

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

    const filtered =
        filters.search !== '' || filters.type !== '' || filters.tag !== '';

    return (
        <>
            <Head title={t('customers.title')} />

            <ScreenHeader
                title={t('customers.title')}
                subtitle={t('customers.count', { count: customers.total })}
                actions={
                    <Link
                        href={create()}
                        className={headerButtonClass}
                        aria-label={t('customers.add')}
                        title={t('customers.add')}
                        data-test="add-customer"
                    >
                        <Plus className="size-5" />
                    </Link>
                }
            />

            <div className="mx-auto w-full max-w-3xl space-y-3 p-4 sm:p-6">
                <SearchField
                    value={filters.search}
                    placeholder={t('customers.search')}
                    onSearch={(search) => apply({ search })}
                />

                <FilterChips
                    label={t('customers.fields.type')}
                    selected={[
                        filters.type,
                        filters.tag ? `tag:${filters.tag}` : '',
                    ]}
                    onChange={(value) =>
                        value.startsWith('tag:')
                            ? apply({
                                  tag:
                                      filters.tag === value.slice(4)
                                          ? ''
                                          : value.slice(4),
                              })
                            : apply({
                                  type: filters.type === value ? '' : value,
                              })
                    }
                    chips={[
                        ...types.map((type) => ({
                            value: type.value,
                            label: type.label,
                        })),
                        ...tags.map((tag) => ({
                            value: `tag:${tag}`,
                            label: `#${tag}`,
                        })),
                    ]}
                />

                {customers.data.length === 0 ? (
                    <EmptyState
                        title={
                            filtered
                                ? t('customers.no_results')
                                : t('customers.empty')
                        }
                    />
                ) : (
                    <ul
                        className="da-card divide-y overflow-hidden"
                        data-test="customer-list"
                    >
                        {customers.data.map((customer) => (
                            <li key={customer.id}>
                                <Link
                                    href={show(customer.id)}
                                    className="flex min-h-16 items-center gap-3 px-3 py-2.5 hover:bg-black/[0.03]"
                                >
                                    <CustomerAvatar
                                        icon={customer.avatar_icon}
                                        name={customer.display_name}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex min-w-0 items-center gap-1.5">
                                            <span className="truncate font-semibold">
                                                {customer.display_name}
                                            </span>
                                            {customer.type !==
                                                'residential' && (
                                                <span className="da-badge shrink-0 rounded-full px-2 text-xs leading-5 font-medium">
                                                    {customer.type_label}
                                                </span>
                                            )}
                                            {customer.tags
                                                .slice(0, 2)
                                                .map((tag) => (
                                                    <span
                                                        key={tag}
                                                        className="shrink-0 text-xs text-muted-foreground"
                                                    >
                                                        #{tag}
                                                    </span>
                                                ))}
                                        </div>
                                        <div className="truncate text-sm text-muted-foreground">
                                            {[
                                                customer.phone &&
                                                    phoneText(customer.phone),
                                                customer.address,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </div>
                                    {(customer.owes ?? []).length > 0 && (
                                        <span
                                            className="shrink-0 text-right text-sm font-semibold whitespace-nowrap text-danger tabular-nums"
                                            data-test="customer-owes"
                                        >
                                            {(customer.owes ?? []).map((o) => (
                                                <span
                                                    key={o.currency}
                                                    className="block"
                                                >
                                                    {t('customers.owes', {
                                                        amount: formatMoney(
                                                            o.amount,
                                                            o.currency,
                                                            auth.company
                                                                ?.locale,
                                                        ),
                                                    })}
                                                </span>
                                            ))}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <PaginationLinks links={customers.links} />
            </div>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [{ title: 'customers.title', href: index() }],
};
