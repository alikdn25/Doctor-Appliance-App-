import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { statistics } from '@/routes/jobs';
import type { Option } from '@/types';

export type JobStats = {
    appliance_category: string | null;
    appliance_brand: string | null;
    only_diagnostic: boolean;
    can_edit: boolean;
};

/**
 * The job's fields for Statistics (owner's task of November 24): appliance type, brand and "Only diagnostic".
 * All optional; filled by the AI and from the appliance, changed here. Each change saves at once.
 */
export function JobStatsCard({
    jobId,
    stats,
    categories,
    manufacturers,
}: {
    jobId: number;
    stats: JobStats;
    categories: Option[];
    manufacturers: string[];
}) {
    const t = useTrans();
    const [brand, setBrand] = useState(stats.appliance_brand ?? '');

    useEffect(
        () => setBrand(stats.appliance_brand ?? ''),
        [stats.appliance_brand],
    );

    const save = (data: Record<string, string | boolean | null>) =>
        router.put(statistics(jobId).url, data, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <div
            className="da-card grid gap-3 p-4 sm:grid-cols-2"
            data-test="job-stats"
        >
            <label className="grid gap-1 text-sm font-medium">
                {t('statistics.fields.appliance_type')}
                <NativeSelect
                    value={stats.appliance_category ?? ''}
                    disabled={!stats.can_edit}
                    onChange={(e) =>
                        save({ appliance_category: e.target.value || null })
                    }
                >
                    <option value="">{t('statistics.fields.not_set')}</option>
                    {categories.map((c) => (
                        <option key={c.value} value={c.value}>
                            {c.label}
                        </option>
                    ))}
                </NativeSelect>
            </label>
            <label className="grid gap-1 text-sm font-medium">
                {t('statistics.fields.brand')}
                <Input
                    value={brand}
                    list={`job-${jobId}-brands`}
                    disabled={!stats.can_edit}
                    autoComplete="off"
                    onChange={(e) => setBrand(e.target.value)}
                    onBlur={() => {
                        if (brand.trim() !== (stats.appliance_brand ?? '')) {
                            save({ appliance_brand: brand.trim() || null });
                        }
                    }}
                />
                <datalist id={`job-${jobId}-brands`}>
                    {manufacturers.map((m) => (
                        <option key={m} value={m} />
                    ))}
                </datalist>
            </label>
            <label className="flex min-h-12 items-center gap-3 text-sm font-medium sm:col-span-2">
                <Checkbox
                    checked={stats.only_diagnostic}
                    disabled={!stats.can_edit}
                    onCheckedChange={(c) =>
                        save({ only_diagnostic: c === true })
                    }
                />
                {t('statistics.fields.only_diagnostic')}
            </label>
        </div>
    );
}
