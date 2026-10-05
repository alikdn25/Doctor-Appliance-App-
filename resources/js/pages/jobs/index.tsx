import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search, SlidersHorizontal, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { STATUS_CIRCLES } from '@/components/jobs/job-card';
import { JobList } from '@/components/jobs/job-list';
import type { Assignable, JobRow } from '@/components/jobs/types';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
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

            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 sm:p-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {t('jobs.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('jobs.count', { count: jobs.total })}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            className="da-soft da-press flex size-12 items-center justify-center rounded-2xl"
                            aria-label={t('common.search')}
                            aria-expanded={searchOpen}
                            onClick={() => setSearchOpen(!searchOpen)}
                        >
                            <Search className="size-6" />
                        </button>
                        <button
                            type="button"
                            className="da-soft da-press flex size-12 items-center justify-center rounded-2xl"
                            aria-label={t('jobs.filters')}
                            aria-expanded={filtersOpen}
                            onClick={() => setFiltersOpen(!filtersOpen)}
                        >
                            <SlidersHorizontal className="size-6" />
                        </button>
                    </div>
                </div>

                {/* Count circles per status; tap to show only that status. */}
                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 py-1">
                    {statuses
                        .filter((o) => (statusCounts[o.value] ?? 0) > 0)
                        .map((o) => (
                            <button
                                key={o.value}
                                type="button"
                                aria-pressed={filters.status === o.value}
                                aria-label={`${o.label}: ${statusCounts[o.value]}`}
                                title={o.label}
                                onClick={() =>
                                    apply({
                                        status:
                                            filters.status === o.value
                                                ? ''
                                                : o.value,
                                    })
                                }
                                className={cn(
                                    'da-press flex size-12 shrink-0 items-center justify-center rounded-full text-lg font-bold text-white shadow-[inset_0_2px_2px_rgba(255,255,255,.45),inset_0_-4px_8px_rgba(0,0,0,.25),0_6px_12px_-4px_rgba(0,0,0,.4)] [text-shadow:0_1px_2px_rgba(0,0,0,.35)]',
                                    filters.status === o.value &&
                                        'ring-3 ring-[#0A6CF5] ring-offset-2',
                                )}
                                style={{
                                    background:
                                        STATUS_CIRCLES[o.value] ??
                                        STATUS_CIRCLES.cancelled,
                                }}
                            >
                                {statusCounts[o.value]}
                            </button>
                        ))}
                </div>

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
                        {canCreate && (
                            <Button asChild variant="outline">
                                <Link href={create()}>
                                    <Plus /> {t('jobs.add')}
                                </Link>
                            </Button>
                        )}
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
