import {
    AddressAutocomplete,
    clearedPlace,
    GEOCODED_FIELDS,
} from '@/components/customers/address-autocomplete';
import { FormField } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { MapPin } from 'lucide-react';
import { useState } from 'react';
import type { Option } from '@/types';
import { Textarea } from '@/components/ui/textarea';
import { usePage } from '@inertiajs/react';
import { useTrans } from '@/lib/i18n';
import type { PropertyFormData } from './types';

type TextField = Exclude<
    keyof PropertyFormData,
    'is_primary' | 'google_place_id' | 'latitude' | 'longitude'
>;

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

/** Suggestions for a property's name ("Home", "Rental"…), one tap. */
const LABEL_HINTS = ['home', 'rental', 'cottage', 'office'] as const;

/**
 * Address, access and on-site contact fields of a property (UX-REDESIGN §8.3): the address is one field with
 * suggestions; the other fields open after a pick or with "Enter manually" (an existing address shows them all).
 * The country is a list of country names. Errors are looked up as `${errorPrefix}${field}`.
 */
export function PropertyFields({
    data,
    onChange,
    errors,
    errorPrefix = '',
    idPrefix = 'property',
    countries = [],
}: {
    data: PropertyFormData;
    onChange: (patch: Partial<PropertyFormData>) => void;
    errors: Record<string, string | undefined>;
    errorPrefix?: string;
    idPrefix?: string;
    countries?: Option[];
}) {
    const t = useTrans();
    const [opened, setOpen] = useState(false);
    // An address being typed or already saved shows all its fields.
    const open =
        opened ||
        data.line1 !== '' ||
        data.city !== '' ||
        Object.keys(errors).some((key) => key.startsWith(errorPrefix));
    const { auth } = usePage().props;
    // State / Province / County and ZIP / Postal code / Postcode, as the company's country calls them.
    const labels =
        auth.company && data.country === auth.company.country
            ? auth.company.address
            : null;
    const label = (field: TextField) =>
        (field === 'region' && labels?.region_label) ||
        (field === 'postal_code' && labels?.postal_label) ||
        t(`properties.fields.${field}`);

    // Typing over a picked address: its place ID and coordinates no longer apply.
    const edit = (field: TextField, value: string) =>
        onChange({
            [field]: value,
            ...((GEOCODED_FIELDS as readonly string[]).includes(field) &&
            data.google_place_id
                ? clearedPlace
                : {}),
        });

    const input = (
        field: TextField,
        className = '',
        autoComplete?: string,
        type = 'text',
    ) => (
        <FormField
            key={field}
            id={`${idPrefix}-${field}`}
            label={label(field)}
            error={errors[`${errorPrefix}${field}`]}
            className={className}
        >
            {field === 'line1' ? (
                <AddressAutocomplete
                    id={`${idPrefix}-${field}`}
                    value={data.line1}
                    autoComplete={autoComplete}
                    country={data.country || auth.company?.country || 'US'}
                    onChange={(value) => edit('line1', value)}
                    onPick={(address) => {
                        onChange({
                            ...address,
                            unit: address.unit ?? data.unit,
                        });
                        setOpen(true);
                    }}
                />
            ) : field === 'country' && countries.length > 0 ? (
                <NativeSelect
                    id={`${idPrefix}-${field}`}
                    value={data.country}
                    autoComplete="country"
                    onChange={(e) => edit('country', e.target.value)}
                >
                    {countries.map((country) => (
                        <option key={country.value} value={country.value}>
                            {country.label}
                        </option>
                    ))}
                </NativeSelect>
            ) : (
                <Input
                    id={`${idPrefix}-${field}`}
                    type={type}
                    value={data[field]}
                    autoComplete={autoComplete}
                    maxLength={field === 'country' ? 2 : undefined}
                    onChange={(e) =>
                        edit(
                            field,
                            field === 'country' || field === 'postal_code'
                                ? e.target.value.toUpperCase()
                                : e.target.value,
                        )
                    }
                />
            )}
        </FormField>
    );

    // The street field stays the same element when the rest opens, so typing keeps the focus.
    if (!open) {
        return (
            <div className="grid gap-3 sm:grid-cols-2">
                {input('line1', 'sm:col-span-2', 'address-line1')}
                <button
                    type="button"
                    className="flex min-h-11 w-fit items-center gap-1.5 text-sm font-medium text-link"
                    onClick={() => setOpen(true)}
                >
                    <MapPin className="size-4" />
                    {t('properties.enter_manually')}
                </button>
            </div>
        );
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {input('line1', 'sm:col-span-2', 'address-line1')}
            {layout.slice(1).map(([field, span, autoComplete]) =>
                field === 'label' ? (
                    <div key="label" className="grid gap-1.5 sm:col-span-2">
                        {input('label', '', autoComplete)}
                        <div className="flex flex-wrap gap-1.5">
                            {LABEL_HINTS.map((hint) => {
                                const text = t(
                                    `properties.label_hints.${hint}`,
                                );

                                return (
                                    <button
                                        key={hint}
                                        type="button"
                                        aria-pressed={data.label === text}
                                        className="da-chip da-press inline-flex h-9 items-center rounded-full px-3 text-sm"
                                        onClick={() =>
                                            onChange({ label: text })
                                        }
                                    >
                                        {text}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    input(field, span, autoComplete)
                ),
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
