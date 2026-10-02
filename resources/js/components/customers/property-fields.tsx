import { FormField } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import type { PropertyFormData } from './types';

type TextField = Exclude<keyof PropertyFormData, 'is_primary'>;

const layout: [TextField, string, string?][] = [
    ['line1', 'sm:col-span-2', 'address-line1'],
    ['unit', '', 'address-line2'],
    ['line2', ''],
    ['city', '', 'address-level2'],
    ['region', '', 'address-level1'],
    ['postal_code', '', 'postal-code'],
    ['country', ''],
    ['label', 'sm:col-span-2'],
    ['gate_code', 'sm:col-span-2'],
];

/**
 * Address, access and on-site contact fields of a property.
 * Errors are looked up as `${errorPrefix}${field}`.
 */
export function PropertyFields({
    data,
    onChange,
    errors,
    errorPrefix = '',
    idPrefix = 'property',
}: {
    data: PropertyFormData;
    onChange: (patch: Partial<PropertyFormData>) => void;
    errors: Record<string, string | undefined>;
    errorPrefix?: string;
    idPrefix?: string;
}) {
    const t = useTrans();

    const input = (
        field: TextField,
        className = '',
        autoComplete?: string,
        type = 'text',
    ) => (
        <FormField
            key={field}
            id={`${idPrefix}-${field}`}
            label={t(`properties.fields.${field}`)}
            error={errors[`${errorPrefix}${field}`]}
            className={className}
        >
            <Input
                id={`${idPrefix}-${field}`}
                type={type}
                value={data[field]}
                autoComplete={autoComplete}
                maxLength={field === 'country' ? 2 : undefined}
                onChange={(e) =>
                    onChange({
                        [field]:
                            field === 'country' || field === 'postal_code'
                                ? e.target.value.toUpperCase()
                                : e.target.value,
                    })
                }
            />
        </FormField>
    );

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <p className="text-xs text-muted-foreground sm:col-span-2">
                {t('properties.address_hint')}
            </p>
            {layout.map(([field, span, autoComplete]) =>
                input(field, span, autoComplete),
            )}
            <FormField
                id={`${idPrefix}-access_notes`}
                label={t('properties.fields.access_notes')}
                error={errors[`${errorPrefix}access_notes`]}
                className="sm:col-span-2"
            >
                <Textarea
                    id={`${idPrefix}-access_notes`}
                    rows={2}
                    value={data.access_notes}
                    onChange={(e) => onChange({ access_notes: e.target.value })}
                />
            </FormField>
            <p className="text-xs text-muted-foreground sm:col-span-2">
                {t('properties.site_contact_hint')}
            </p>
            {input('site_contact_name')}
            {input('site_contact_phone', '', undefined, 'tel')}
        </div>
    );
}
