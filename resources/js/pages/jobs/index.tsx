import { Head, Link, router } from '@inertiajs/react';
import { Plus, SlidersHorizontal, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { JobList } from '@/components/jobs/job-list';
import type { Assignable, JobRow } from '@/components/jobs/types';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { create, index, trash } from '@/routes/jobs';
import type { Option } from '@/types';

type Filters = {
    search: string;
    status: string;
    brand: string;
    technician: string;
    type: string;
    visit_type: string;
    outcome: string;
    strict: string;
    from: string;
    to: string;
};

export default function JobsIndex({
    jobs,
    filters,
    statuses,
    types,
    brands,
    technicians,
    canCreate,
    visitTypes,
    outcomes,
    canViewTrash,
}: {
    jobs: Paginated<JobRow>;
    filters: Filters;
    statuses: Option[];
    types: Option[];
    visitTypes: Option[];
    outcomes: Option[];
    canViewTrash: boolean;
    brands: Option[];
    technicians: Assignable[];
    canCreate: boolean;
}) {
    const t = useTrans();
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

    const filtered = Object.values(filters).some((v) => v !== '');
    const activeFilters = Object.entries(filters).filter(
        ([key, value]) => key !== 'search' && value !== '',
    ).length;
    // On a phone the list comes first; the filters open on demand.
    const [showFilters, setShowFilters] = useState(activeFilters > 0);

    return (
        <>
            <Head title={t('jobs.title')} />

            <div className="p-4">
                <PageHeader
                    title={t('jobs.title')}
                    description={t('jobs.count', { count: jobs.total })}
                    actions={
                        canCreate && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus /> {t('jobs.add')}
                                </Link>
                            </Button>
                        )
                    }
                />

                <form onSubmit={submit} className="mb-3 flex gap-2">
                    <Input
                        type="search"
                        value={search}
                        placeholder={t('jobs.search')}
                        aria-label={t('common.search')}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        {t('common.search')}
                    </Button>
                    <Button
                        type="button"
                        variant={activeFilters > 0 ? 'secondary' : 'outline'}
                        className="md:hidden"
                        aria-expanded={showFilters}
                        aria-label={t('jobs.filters')}
                        onClick={() => setShowFilters((open) => !open)}
                    >
                        <SlidersHorizontal />
                        {activeFilters > 0 && activeFilters}
                    </Button>
                </form>

                <div
                    className={`mb-4 grid-cols-2 gap-2 md:grid md:grid-cols-4 xl:grid-cols-7 ${showFilters ? 'grid' : 'hidden'}`}
                >
                    <NativeSelect
                        aria-label={t('jobs.change_status')}
                        value={filters.status}
                        onChange={(e) => apply({ status: e.target.value })}
                    >
                        <option value="">{t('jobs.all_statuses')}</option>
                        <option value="open">{t('jobs.open_jobs')}</option>
                        {statuses.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label={t('jobs.fields.job_type')}
                        value={filters.type}
                        onChange={(e) => apply({ type: e.target.value })}
                    >
                        <option value="">{t('jobs.all_types')}</option>
                        {types.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label={t('jobs.fields.visit_type')}
                        value={filters.visit_type}
                        onChange={(e) => apply({ visit_type: e.target.value })}
                    >
                        <option value="">{t('jobs.all_visit_types')}</option>
                        {visitTypes.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label={t('jobs.close.outcome')}
                        value={filters.outcome}
                        onChange={(e) => apply({ outcome: e.target.value })}
                    >
                        <option value="">{t('jobs.all_outcomes')}</option>
                        <option value="none">
                            {t('jobs.open_no_outcome')}
                        </option>
                        {outcomes.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <label className="flex min-h-9 items-center gap-2 rounded-md border px-3 text-sm">
                        <Checkbox
                            checked={filters.strict === '1'}
                            onCheckedChange={(c) =>
                                apply({ strict: c === true ? '1' : '' })
                            }
                        />
                        {t('jobs.strict.filter')}
                    </label>
                    {brands.length > 1 && (
                        <NativeSelect
                            aria-label={t('jobs.fields.brand')}
                            value={filters.brand}
                            onChange={(e) => apply({ brand: e.target.value })}
                        >
                            <option value="">{t('jobs.all_brands')}</option>
                            {brands.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                    <NativeSelect
                        aria-label={t('jobs.visit_fields.assignee_ids')}
                        value={filters.technician}
                        onChange={(e) => apply({ technician: e.target.value })}
                    >
                        <option value="">{t('jobs.all_technicians')}</option>
                        {technicians.map((u) => (
                            <option key={u.id} value={String(u.id)}>
                                {u.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label={t('jobs.from')}
                        title={t('jobs.from')}
                        value={filters.from}
                        onChange={(e) => apply({ from: e.target.value })}
                    />
                    <Input
                        type="date"
                        aria-label={t('jobs.to')}
                        title={t('jobs.to')}
                        value={filters.to}
                        onChange={(e) => apply({ to: e.target.value })}
                    />
                    {filtered && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                router.get(index().url);
                            }}
                        >
                            {t('jobs.clear_filters')}
                        </Button>
                    )}
                </div>

                <JobList
                    jobs={jobs.data}
                    empty={filtered ? t('jobs.no_results') : t('jobs.empty')}
                />

                <PaginationLinks links={jobs.links} />

                {canViewTrash && (
                    <Button variant="ghost" size="sm" className="mt-4" asChild>
                        <Link href={trash()}>
                            <Trash2 /> {t('jobs.trash.title')}
                        </Link>
                    </Button>
                )}
            </div>
        </>
    );
}

JobsIndex.layout = {
    breadcrumbs: [{ title: 'jobs.title', href: index() }],
};
