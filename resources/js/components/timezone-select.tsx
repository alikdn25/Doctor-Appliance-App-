import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import type { Option } from '@/types';

export function TimezoneSelect({
    id = 'timezone',
    value,
    options,
    onChange,
}: {
    id?: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    const t = useTrans();
    const [query, setQuery] = useState('');
    const search = query.trim().toLowerCase().replaceAll('−', '-');
    const filtered = options.filter(
        (option) =>
            `${option.label} ${option.value}`.toLowerCase().includes(search) ||
            option.value === value,
    );
    return (
        <div className="space-y-2">
            <Input
                id={`${id}-search`}
                type="search"
                aria-label={t('company.timezone_search')}
                placeholder={t('company.timezone_search')}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                className="h-11"
            />
            <NativeSelect
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-11"
            >
                {!value && (
                    <option value="">{t('company.timezone_choose')}</option>
                )}
                {filtered.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </NativeSelect>
            <p className="text-xs text-muted-foreground">
                {t('company.timezone_dst')}
            </p>
        </div>
    );
}
