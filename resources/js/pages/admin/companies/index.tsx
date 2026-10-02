import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Clock, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { create, index, show } from '@/routes/admin/companies';

type CompanyRow = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    plan: string | null;
    subscription_status: string | null;
    brands_count: number;
    members_count: number;
    created_at: string;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

type Tzdata = {
    version: string | null;
    released: string | null;
    outdated: boolean | null;
};

export default function AdminCompaniesIndex({
    companies,
    filters,
    tzdata,
}: {
    companies: Paginated<CompanyRow>;
    filters: { search: string };
    tzdata: Tzdata;
}) {
    const t = useTrans();
    const [search, setSearch] = useState(filters.search);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            index().url,
            { search },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('admin.companies.title')} />

            <div className="p-4">
                <PageHeader
                    title={t('admin.companies.title')}
                    description={t('admin.companies.description', {
                        count: companies.total,
                    })}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> {t('admin.companies.add')}
                            </Link>
                        </Button>
                    }
                />

                {tzdata.outdated !== false && (
                    <Alert variant="destructive" className="mb-4">
                        <Clock />
                        <AlertTitle>
                            {t('admin.tzdata_outdated_title')}
                        </AlertTitle>
                        <AlertDescription>
                            {tzdata.outdated
                                ? t('admin.tzdata_outdated', {
                                      version: tzdata.version,
                                      released: tzdata.released,
                                  })
                                : t('admin.tzdata_unknown')}
                        </AlertDescription>
                    </Alert>
                )}

                <form onSubmit={submit} className="mb-4 flex gap-2">
                    <Input
                        type="search"
                        value={search}
                        placeholder={t('admin.companies.search')}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        {t('common.search')}
                    </Button>
                </form>

                <ul className="divide-y rounded-lg border">
                    {companies.data.map((company) => (
                        <li key={company.id}>
                            <Link
                                href={show(company.id)}
                                className="flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-muted/50"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {company.name}
                                        </span>
                                        <Badge
                                            variant={
                                                company.status === 'active'
                                                    ? 'outline'
                                                    : 'destructive'
                                            }
                                        >
                                            {company.status_label}
                                        </Badge>
                                        {company.plan && (
                                            <Badge variant="secondary">
                                                {company.plan}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {t('admin.companies.usage', {
                                            brands: company.brands_count,
                                            members: company.members_count,
                                        })}
                                        {company.subscription_status &&
                                            ` · ${company.subscription_status}`}
                                        {` · ${company.created_at}`}
                                    </div>
                                </div>
                                <ChevronRight className="size-4 text-muted-foreground" />
                            </Link>
                        </li>
                    ))}
                    {companies.data.length === 0 && (
                        <li className="p-6 text-center text-sm text-muted-foreground">
                            {t('admin.companies.empty')}
                        </li>
                    )}
                </ul>

                {companies.links.length > 3 && (
                    <nav className="mt-4 flex flex-wrap gap-1">
                        {companies.links.map((link, i) =>
                            link.url ? (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    asChild
                                >
                                    <Link
                                        href={link.url}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                </Button>
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

AdminCompaniesIndex.layout = {
    breadcrumbs: [{ title: 'admin.companies.title', href: index() }],
};
