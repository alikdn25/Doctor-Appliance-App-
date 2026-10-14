import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import { HistoryEntryRow } from '@/components/record-history';
import type { HistoryEntry } from '@/components/record-history';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { changeLog } from '@/routes/company';

type Filters = {
    type: string | null;
    source: string | null;
    user: number | null;
};

/**
 * Settings → Change log (Owner): every create, change and delete of customers, jobs, estimates, invoices and
 * payments, newest first.
 */
export default function ChangeLog({
    entries,
    filters,
    people,
}: {
    entries: {
        data: HistoryEntry[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: Filters;
    people: { id: number; name: string }[];
}) {
    const t = useTrans();

    const apply = (patch: Partial<Filters>) => {
        const next = { ...filters, ...patch };
        router.get(
            changeLog().url,
            Object.fromEntries(
                Object.entries(next).filter(([, v]) => v !== null && v !== ''),
            ),
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('history.change_log')} />

            <div className="max-w-3xl space-y-4 p-4">
                <PageHeader
                    title={t('history.change_log')}
                    description={t('history.change_log_hint')}
                />

                <div className="grid gap-2 sm:grid-cols-3">
                    <NativeSelect
                        aria-label={t('history.all_types')}
                        value={filters.type ?? ''}
                        onChange={(e) =>
                            apply({ type: e.target.value || null })
                        }
                    >
                        <option value="">{t('history.all_types')}</option>
                        {[
                            'customer',
                            'job',
                            'estimate',
                            'invoice',
                            'payment',
                        ].map((type) => (
                            <option key={type} value={type}>
                                {t(`history.types.${type}`)}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label={t('history.all_sources')}
                        value={filters.source ?? ''}
                        onChange={(e) =>
                            apply({ source: e.target.value || null })
                        }
                    >
                        <option value="">{t('history.all_sources')}</option>
                        {['web', 'bot', 'public', 'console'].map((source) => (
                            <option key={source} value={source}>
                                {t(`history.sources.${source}`)}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label={t('history.all_people')}
                        value={filters.user ? String(filters.user) : ''}
                        onChange={(e) =>
                            apply({
                                user: e.target.value
                                    ? Number(e.target.value)
                                    : null,
                            })
                        }
                    >
                        <option value="">{t('history.all_people')}</option>
                        {people.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                {entries.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('history.empty')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card shadow-sm">
                        {entries.data.map((entry) => (
                            <HistoryEntryRow
                                key={entry.id}
                                entry={entry}
                                showRecord
                            />
                        ))}
                    </ul>
                )}

                <PaginationLinks links={entries.links} />
            </div>
        </>
    );
}

ChangeLog.layout = {
    breadcrumbs: [{ title: 'history.change_log', href: changeLog() }],
};
