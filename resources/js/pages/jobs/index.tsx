import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowDownWideNarrow,
    RotateCcw,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { formatMoney } from '@/components/billing/money';
import { InboxHeaderButton } from '@/components/inbox-header-button';
import { JobList } from '@/components/jobs/job-list';
import type { Assignable, JobRow } from '@/components/jobs/types';
import { isFinishedJob } from '@/components/jobs/types';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTable } from '@/components/ui/data-table';
import { FilterChips } from '@/components/ui/filter-chips';
import type { FilterChip } from '@/components/ui/filter-chips';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { SearchField } from '@/components/ui/search-field';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { StatusBadge } from '@/components/ui/status-badge';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { index, restore, show } from '@/routes/jobs';
import type { BacklogReason } from '@/types/job-backlog';
import type { Option } from '@/types';

type Tab = 'open' | 'all' | 'deleted';

type AllFilters = {
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

type DeletedJob = {
    id: number;
    number: number;
    customer: string | null;
    address: string | null;
    brand: string | null;
    deleted_at: string | null;
    deleted_by: string | null;
    restorable_until: string | null;
};

const REASONS: BacklogReason[] = [
    'overdue',
    'needs_schedule',
    'waiting_for_parts',
    'waiting_for_customer',
    'on_hold',
    'scheduled',
];

/**
 * Jobs (UX-REDESIGN §7.4): one screen with Open (the unfinished queue by urgency, with its count) · All · Deleted,
 * a live search, one line of filter chips (zeros muted, at the end), compact office cards, and the sort under "⋯".
 * Deleted rows carry Restore; the 30-day note is said once at the top.
 */
export default function JobsIndex({
    tab,
    sort = '',
    dir = 'desc',
    jobs,
    filters,
    statusCounts = {},
    statuses = [],
    types = [],
    visitTypes = [],
    outcomes = [],
    brands = [],
    technicians = [],
    deleted = [],
    days = 30,
    canViewAll,
    canViewTrash,
    deletedCount = 0,
}: {
    tab: Tab;
    sort?: string;
    dir?: 'asc' | 'desc';
    jobs?: Paginated<JobRow>;
    filters: Partial<AllFilters> & { search: string; reason?: string };
    statusCounts?: Record<string, number>;
    statuses?: Option[];
    types?: Option[];
    visitTypes?: Option[];
    outcomes?: Option[];
    brands?: Option[];
    technicians?: Assignable[];
    deleted?: DeletedJob[];
    days?: number;
    canViewAll: boolean;
    canViewTrash: boolean;
    canCreate: boolean;
    deletedCount?: number;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const { unfinishedJobs, auth } = usePage().props;
    const [filtersOpen, setFiltersOpen] = useState(
        tab === 'all' &&
            Object.entries(filters).some(
                ([key, value]) =>
                    !['search', 'status'].includes(key) && value !== '',
            ),
    );

    const go = (query: Record<string, string>, replace = true) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ tab, ...query } as Record<
                    string,
                    string
                >).filter(([, value]) => value !== ''),
            ),
            { preserveState: true, preserveScroll: true, replace },
        );
    const current = Object.fromEntries(
        Object.entries(filters).map(([key, value]) => [
            key,
            String(value ?? ''),
        ]),
    ) as Record<string, string>;
    const apply = (next: Record<string, string>) =>
        go({ ...current, ...(sort ? { sort, dir } : {}), ...next });

    const openCount = unfinishedJobs?.total ?? 0;
    const tabs = [
        {
            value: 'open' as const,
            label: `${t('jobs.tabs.open')} ${openCount}`,
        },
        { value: 'all' as const, label: t('jobs.tabs.all') },
        ...(canViewTrash
            ? [
                  {
                      value: 'deleted' as const,
                      label: deletedCount
                          ? `${t('jobs.tabs.deleted')} ${deletedCount}`
                          : t('jobs.tabs.deleted'),
                  },
              ]
            : []),
    ];

    // One line of chips; a chip with nothing in it is muted and goes to the end.
    const sortChips = (chips: FilterChip[]) => [
        ...chips.filter((c) => c.count !== 0),
        ...chips.filter((c) => c.count === 0),
    ];
    const chips: FilterChip[] =
        tab === 'open'
            ? sortChips(
                  REASONS.map((reason) => ({
                      value: reason,
                      label: t(`jobs.backlog.reasons.${reason}`),
                      count: unfinishedJobs?.counts[reason] ?? 0,
                      danger: reason === 'overdue',
                  })),
              )
            : tab === 'all'
              ? statuses
                    .filter((o) => (statusCounts[o.value] ?? 0) > 0)
                    .map((o) => ({
                        value: o.value,
                        label: o.label,
                        count: statusCounts[o.value] ?? 0,
                    }))
              : [];
    const chipKey = tab === 'open' ? 'reason' : 'status';
    const selectedChip = current[chipKey] ?? '';

    const sortItems =
        tab === 'open'
            ? [
                  { key: 'urgency', label: t('jobs.sort.urgency') },
                  { key: 'newest', label: t('jobs.sort.newest') },
              ]
            : tab === 'all'
              ? [
                    { key: 'visit', label: t('jobs.sort.visit') },
                    { key: 'number', label: t('jobs.sort.number') },
                ]
              : [];
    const activeSort = sort || sortItems[0]?.key;
    const filtered =
        tab === 'all' &&
        Object.entries(current).some(
            ([key, value]) => key !== 'sort' && value !== '',
        );

    return (
        <>
            <Head title={t('jobs.title')} />

            <ScreenHeader
                title={canViewAll ? t('jobs.title') : t('jobs.backlog.title')}
                subtitle={
                    tab === 'deleted'
                        ? t('jobs.count', { count: deleted.length })
                        : jobs
                          ? t('jobs.count', { count: jobs.total })
                          : undefined
                }
                actions={
                    <>
                        <InboxHeaderButton />
                        {tab === 'all' && (
                            <button
                                type="button"
                                className={headerButtonClass}
                                aria-label={t('jobs.filters')}
                                aria-expanded={filtersOpen}
                                onClick={() => setFiltersOpen(!filtersOpen)}
                            >
                                <SlidersHorizontal className="size-5" />
                            </button>
                        )}
                        {sortItems.length > 0 && (
                            <ActionSheet
                                title={t('jobs.sort.title')}
                                items={sortItems.map((item) => ({
                                    key: item.key,
                                    label: item.label,
                                    hint:
                                        item.key === activeSort
                                            ? t('jobs.sort.current')
                                            : undefined,
                                    icon: <ArrowDownWideNarrow />,
                                    onSelect: () =>
                                        apply({
                                            sort:
                                                item.key === sortItems[0].key
                                                    ? ''
                                                    : item.key,
                                        }),
                                }))}
                            />
                        )}
                    </>
                }
            />

            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 sm:p-6 lg:max-w-[1440px]">
                {canViewAll && (
                    <SegmentedControl
                        label={t('jobs.title')}
                        className="flex w-full"
                        value={tab}
                        onChange={(next) => go({ tab: next }, false)}
                        options={tabs}
                    />
                )}

                {tab !== 'deleted' && (
                    <SearchField
                        value={filters.search}
                        placeholder={t('jobs.search')}
                        onSearch={(search) => apply({ search })}
                    />
                )}

                {chips.length > 0 && (
                    <FilterChips
                        label={t('jobs.filters')}
                        selected={selectedChip ? [selectedChip] : []}
                        onChange={(value) =>
                            apply({
                                [chipKey]: value === selectedChip ? '' : value,
                            })
                        }
                        chips={chips}
                    />
                )}

                {tab === 'all' && filtersOpen && (
                    <div className="da-card grid grid-cols-2 gap-2 p-3 md:grid-cols-3">
                        <NativeSelect
                            aria-label={t('jobs.fields.job_type')}
                            value={current.type ?? ''}
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
                            aria-label={t('jobs.fields.visit_reason')}
                            value={current.visit_type ?? ''}
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
                            value={current.outcome ?? ''}
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
                        <label className="flex min-h-11 items-center gap-2 rounded-xl border px-3 text-sm">
                            <Checkbox
                                checked={current.strict === '1'}
                                onCheckedChange={(c) =>
                                    apply({ strict: c === true ? '1' : '' })
                                }
                            />
                            {t('jobs.strict.filter')}
                        </label>
                        {brands.length > 1 && (
                            <NativeSelect
                                aria-label={t('jobs.fields.brand')}
                                value={current.brand ?? ''}
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
                        {!auth.company?.works_alone && (
                            <NativeSelect
                                aria-label={t('jobs.visit_fields.assignee_ids')}
                                value={current.technician ?? ''}
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
                        )}
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">
                            {t('jobs.from')}
                            <Input
                                type="date"
                                value={current.from ?? ''}
                                onChange={(e) =>
                                    apply({ from: e.target.value })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">
                            {t('jobs.to')}
                            <Input
                                type="date"
                                value={current.to ?? ''}
                                onChange={(e) => apply({ to: e.target.value })}
                            />
                        </label>
                        {filtered && (
                            <Button
                                variant="ghost"
                                onClick={() => go({ tab: 'all' })}
                            >
                                {t('jobs.clear_filters')}
                            </Button>
                        )}
                    </div>
                )}

                {tab === 'deleted' ? (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {t('jobs.trash.hint', { days })}
                        </p>
                        {deleted.length === 0 ? (
                            <p className="da-card p-10 text-center text-sm text-muted-foreground">
                                {t('jobs.trash.empty', { days })}
                            </p>
                        ) : (
                            <ul
                                className="da-card divide-y overflow-hidden"
                                data-test="deleted-jobs"
                            >
                                {deleted.map((job) => (
                                    <li
                                        key={job.id}
                                        className="flex items-center gap-3 p-3 text-sm"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-semibold">
                                                #{job.number} · {job.customer}
                                            </p>
                                            {job.address && (
                                                <p className="truncate text-muted-foreground">
                                                    {job.address}
                                                </p>
                                            )}
                                            {job.deleted_at && (
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {t(
                                                        'jobs.trash.deleted_by',
                                                        {
                                                            date: time.dateTime(
                                                                job.deleted_at,
                                                            ),
                                                            name:
                                                                job.deleted_by ??
                                                                '—',
                                                        },
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() =>
                                                router.post(restore(job.id).url)
                                            }
                                        >
                                            <RotateCcw /> {t('jobs.restore')}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                ) : (
                    jobs && (
                        <>
                            {jobs.data.length > 0 && (
                                <DataTable
                                    className="hidden lg:block"
                                    label={t('jobs.title')}
                                    rows={jobs.data}
                                    rowKey={(job) => job.id}
                                    rowHref={(job) => show(job.id)}
                                    rowClassName={(job) =>
                                        isFinishedJob(job.status) &&
                                        'opacity-60 grayscale'
                                    }
                                    sort={
                                        tab === 'all'
                                            ? sort || 'visit'
                                            : undefined
                                    }
                                    dir={dir}
                                    sortUrl={index().url}
                                    query={{ tab, ...current }}
                                    columns={[
                                        {
                                            key: 'number',
                                            label: '#',
                                            sort:
                                                tab === 'all'
                                                    ? 'number'
                                                    : undefined,
                                            render: (job) => `#${job.number}`,
                                        },
                                        {
                                            key: 'status',
                                            label: t('jobs.columns.status'),
                                            sort:
                                                tab === 'all'
                                                    ? 'status'
                                                    : undefined,
                                            render: (job) => (
                                                <StatusBadge
                                                    status={job.status}
                                                    label={job.status_label}
                                                />
                                            ),
                                        },
                                        {
                                            key: 'date',
                                            label: t('jobs.columns.date'),
                                            sort:
                                                tab === 'all'
                                                    ? 'visit'
                                                    : undefined,
                                            className: 'whitespace-nowrap',
                                            render: (job) =>
                                                job.visit
                                                    ? `${time.day(job.visit.scheduled_start)} · ${time.shortRange(job.visit.scheduled_start, job.visit.scheduled_end)}`
                                                    : t('jobs.not_scheduled'),
                                        },
                                        {
                                            key: 'customer',
                                            label: t('jobs.columns.customer'),
                                            sort:
                                                tab === 'all'
                                                    ? 'customer'
                                                    : undefined,
                                            render: (job) =>
                                                job.customer ?? '—',
                                        },
                                        {
                                            key: 'address',
                                            label: t('jobs.columns.address'),
                                            className: 'max-w-64 truncate',
                                            render: (job) => job.address ?? '—',
                                        },
                                        {
                                            key: 'appliance',
                                            label: t('jobs.columns.appliance'),
                                            render: (job) =>
                                                job.appliance_types.join(
                                                    ', ',
                                                ) || job.job_type_label,
                                        },
                                        {
                                            key: 'technician',
                                            label: t('jobs.columns.technician'),
                                            render: (job) =>
                                                job.visit?.assignees.join(
                                                    ', ',
                                                ) || '—',
                                        },
                                        {
                                            key: 'total',
                                            label: t('jobs.columns.total'),
                                            sort:
                                                tab === 'all'
                                                    ? 'total'
                                                    : undefined,
                                            align: 'right',
                                            render: (job) =>
                                                job.total
                                                    ? formatMoney(
                                                          job.total.amount,
                                                          job.total.currency,
                                                          auth.company?.locale,
                                                      )
                                                    : '—',
                                        },
                                    ]}
                                />
                            )}
                            <div className="lg:hidden">
                                <JobList
                                    jobs={jobs.data}
                                    empty={
                                        filters.search ||
                                        selectedChip ||
                                        filtered
                                            ? t('jobs.no_results')
                                            : tab === 'open'
                                              ? t('jobs.backlog.empty')
                                              : t('jobs.empty')
                                    }
                                />
                            </div>
                            {jobs.data.length === 0 && (
                                <div className="hidden lg:block">
                                    <JobList
                                        jobs={[]}
                                        empty={
                                            filters.search ||
                                            selectedChip ||
                                            filtered
                                                ? t('jobs.no_results')
                                                : tab === 'open'
                                                  ? t('jobs.backlog.empty')
                                                  : t('jobs.empty')
                                        }
                                    />
                                </div>
                            )}
                            <PaginationLinks links={jobs.links} />
                        </>
                    )
                )}
            </div>
        </>
    );
}

JobsIndex.layout = {
    breadcrumbs: [{ title: 'jobs.title', href: index() }],
};
