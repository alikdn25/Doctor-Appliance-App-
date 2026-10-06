import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, MapPin, Phone, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { CustomerAvatar } from '@/components/customers/customer-avatar';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
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
};

type Filters = { search: string; type: string; tag: string };

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

    const filtered =
        filters.search !== '' || filters.type !== '' || filters.tag !== '';

    return (
        <>
            <Head title={t('customers.title')} />

            <div className="p-4">
                <PageHeader
                    title={t('customers.title')}
                    description={t('customers.count', {
                        count: customers.total,
                    })}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> {t('customers.add')}
                            </Link>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="mb-3 flex gap-2">
                    <Input
                        type="search"
                        value={search}
                        placeholder={t('customers.search')}
                        aria-label={t('common.search')}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        {t('common.search')}
                    </Button>
                </form>

                <div className="mb-4 grid grid-cols-2 gap-2 sm:flex sm:max-w-md">
                    <NativeSelect
                        aria-label={t('customers.fields.type')}
                        value={filters.type}
                        onChange={(e) => apply({ type: e.target.value })}
                    >
                        <option value="">{t('customers.all_types')}</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </NativeSelect>
                    {tags.length > 0 && (
                        <NativeSelect
                            aria-label={t('customers.fields.tags')}
                            value={filters.tag}
                            onChange={(e) => apply({ tag: e.target.value })}
                        >
                            <option value="">{t('customers.all_tags')}</option>
                            {tags.map((tag) => (
                                <option key={tag} value={tag}>
                                    {tag}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                </div>

                <ul className="divide-y rounded-lg border">
                    {customers.data.map((customer) => (
                        <li key={customer.id}>
                            <Link
                                href={show(customer.id)}
                                className="flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-muted/50"
                            >
                                <CustomerAvatar
                                    icon={customer.avatar_icon}
                                    name={customer.display_name}
                                />
                                <div className="min-w-0 flex-1 space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {customer.display_name}
                                        </span>
                                        {customer.type !== 'residential' && (
                                            <Badge variant="outline">
                                                {customer.type_label}
                                            </Badge>
                                        )}
                                        {customer.tags.map((tag) => (
                                            <Badge
                                                key={tag}
                                                variant="secondary"
                                            >
                                                {tag}
                                            </Badge>
                                        ))}
                                    </div>
                                    <div className="flex flex-col gap-0.5 text-xs text-muted-foreground sm:flex-row sm:gap-4">
                                        {customer.phone && (
                                            <span className="flex items-center gap-1">
                                                <Phone className="size-3" />
                                                {phoneText(customer.phone)}
                                            </span>
                                        )}
                                        {customer.address && (
                                            <span className="flex min-w-0 items-center gap-1">
                                                <MapPin className="size-3 shrink-0" />
                                                <span className="truncate">
                                                    {customer.address}
                                                </span>
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                            </Link>
                        </li>
                    ))}
                    {customers.data.length === 0 && (
                        <li className="p-6 text-center text-sm text-muted-foreground">
                            {filtered
                                ? t('customers.no_results')
                                : t('customers.empty')}
                        </li>
                    )}
                </ul>

                <PaginationLinks links={customers.links} />
            </div>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [{ title: 'customers.title', href: index() }],
};
