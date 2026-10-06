import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search, SlidersHorizontal, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { StatusCircles } from '@/components/jobs/job-card';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { JobList } from '@/components/jobs/job-list';
import type { Assignable, JobRow } from '@/components/jobs/types';
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
    statusCounts = {},
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
    statusCounts?: Record<string, number>;
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
    const [searchOpen, setSearchOpen] = useState(filters.search !== '');
    const [legendOpen, setLegendOpen] = useState(false);
    // Filters other than search and status stay folded away until asked for.
    const [filtersOpen, setFiltersOpen] = useState(
        Object.entries(filters).some(
            ([key, value]) =>
                !['search', 'status'].includes(key) && value !== '',
        ),
    );

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

    return (
        <>
            <Head title={t('jobs.title')} />

            <ScreenHeader
                title={t('jobs.title')}
                subtitle={t('jobs.count', { count: jobs.total })}
                actions={
                    <>
                        <button
                            type="button"
                            className={headerButtonClass}
                            aria-label={t('common.search')}
                            aria-expanded={searchOpen}
                            onClick={() => setSearchOpen(!searchOpen)}
                        >
                            <Search className="size-6" />
                        </button>
                        <button
                            type="button"
                            className={headerButtonClass}
                            aria-label={t('jobs.filters')}
                            aria-expanded={filtersOpen}
                            onClick={() => setFiltersOpen(!filtersOpen)}
                        >
                            <SlidersHorizontal className="size-6" />
                        </button>
                    </>
                }
            />

            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 sm:p-6">
                <StatusCircles
                    counts={statusCounts}
                    labels={Object.fromEntries(
                        statuses.map((o) => [o.value, o.label]),
                    )}
                    selected={filters.status}
                    onSelect={(status) => apply({ status })}
                    open={legendOpen}
                    onOpenChange={setLegendOpen}
                />

                {searchOpen && (
                    <form onSubmit={submit} className="flex gap-2">
                        <Input
                            type="search"
                            autoFocus
                            value={search}
                            placeholder={t('jobs.search')}
                            aria-label={t('common.search')}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <Button type="submit" variant="outline">
                            {t('common.search')}
                        </Button>
                    </form>
                )}

                {filtersOpen && (
                    <div className="da-card grid grid-cols-2 gap-2 p-3 md:grid-cols-3">
                        {canViewTrash && (
                            <Button variant="outline" asChild>
                                <Link href={trash()}>
                                    <Trash2 /> {t('jobs.trash.title')}
                                </Link>
                            </Button>
                        )}
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
                            onChange={(e) =>
                                apply({ visit_type: e.target.value })
                            }
                        >
                            <option value="">
                                {t('jobs.all_visit_types')}
                            </option>
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
                                onChange={(e) =>
                                    apply({ brand: e.target.value })
                                }
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
                            onChange={(e) =>
                                apply({ technician: e.target.value })
                            }
                        >
                            <option value="">
                                {t('jobs.all_technicians')}
                            </option>
                            {technicians.map((u) => (
                                <option key={u.id} value={String(u.id)}>
                                    {u.name}
                                </option>
                            ))}
                        </NativeSelect>
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">
                            {t('jobs.from')}
                            <Input
                                type="date"
                                value={filters.from}
                                onChange={(e) =>
                                    apply({ from: e.target.value })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">
                            {t('jobs.to')}
                            <Input
                                type="date"
                                value={filters.to}
                                onChange={(e) => apply({ to: e.target.value })}
                            />
                        </label>
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
                )}

                <h2 className="pt-1 text-lg font-bold">
                    {(statuses.find((o) => o.value === filters.status)?.label ??
                        t('jobs.title')) + ` (${jobs.total})`}
                </h2>

                <JobList
                    jobs={jobs.data}
                    empty={filtered ? t('jobs.no_results') : t('jobs.empty')}
                />

                <PaginationLinks links={jobs.links} />

                {canCreate && (
                    <Button asChild className="h-14 w-full text-lg">
                        <Link href={create({ query: { book: 1 } })}>
                            <Plus className="size-6" /> {t('nav.book_customer')}
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
