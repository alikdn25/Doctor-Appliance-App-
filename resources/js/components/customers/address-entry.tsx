import { usePage } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    AddressAutocomplete,
    clearedPlace,
} from '@/components/customers/address-autocomplete';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';

export type AddressData = {
    line1: string;
    unit: string;
    city: string;
    region: string;
    postal_code: string;
    country: string;
    google_place_id: string;
    latitude: string;
    longitude: string;
};

export const emptyAddress = (country: string): AddressData => ({
    line1: '',
    unit: '',
    city: '',
    region: '',
    postal_code: '',
    country,
    ...clearedPlace,
});

/** Street and city are what an address needs to be saved. */
export const addressComplete = (a: AddressData) =>
    a.line1.trim() !== '' && a.city.trim() !== '';

/**
 * One address field with suggestions (Google Places when the company has a key); picking a suggestion fills unit,
 * city, region and postal code, "Enter manually" opens them for typing. Errors are looked up as `${errorPrefix}line1`.
 */
export function AddressEntry({
    id,
    value,
    onChange,
    errors = {},
    errorPrefix = '',
    label,
    autoFocus,
    optional = false,
}: {
    id: string;
    value: AddressData;
    onChange: (patch: Partial<AddressData>) => void;
    errors?: Record<string, string | undefined>;
    errorPrefix?: string;
    label?: string;
    autoFocus?: boolean;
    /** Not needed to save (e.g. a billing address): no required marks. */
    optional?: boolean;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const labels = auth.company?.address;
    const [open, setOpen] = useState(
        value.city !== '' || value.unit !== '' || value.postal_code !== '',
    );
    const error = (field: string) => errors[`${errorPrefix}${field}`];
    // Filled from outside (e.g. "Paste from message"): show the parts so they can be checked.
    const filledParts =
        value.city !== '' || value.unit !== '' || value.postal_code !== '';
    useEffect(() => {
        if (filledParts) setOpen(true);
    }, [filledParts]);

    return (
        <div className="space-y-2">
            <FormField
                id={id}
                label={label ?? t('jobs.quick.address')}
                error={error('line1')}
            >
                <AddressAutocomplete
                    id={id}
                    autoComplete="street-address"
                    autoFocus={autoFocus}
                    required={!optional}
                    value={value.line1}
                    country={value.country}
                    aria-invalid={!!error('line1')}
                    onChange={(line1) => onChange({ line1, ...clearedPlace })}
                    onPick={(address) => {
                        onChange({
                            ...address,
                            unit: address.unit ?? value.unit,
                        });
                        setOpen(true);
                    }}
                />
            </FormField>
            {open ? (
                <div className="grid grid-cols-2 gap-3">
                    <FormField
                        id={`${id}-unit`}
                        label={t('jobs.quick.unit')}
                        error={error('unit')}
                    >
                        <Input
                            id={`${id}-unit`}
                            autoComplete="address-line2"
                            maxLength={50}
                            value={value.unit}
                            onChange={(e) => onChange({ unit: e.target.value })}
                        />
                    </FormField>
                    <FormField
                        id={`${id}-city`}
                        label={t('properties.fields.city')}
                        error={error('city')}
                    >
                        <Input
                            id={`${id}-city`}
                            autoComplete="address-level2"
                            maxLength={100}
                            required={!optional}
                            value={value.city}
                            onChange={(e) =>
                                onChange({
                                    city: e.target.value,
                                    ...clearedPlace,
                                })
                            }
                        />
                    </FormField>
                    <FormField
                        id={`${id}-region`}
                        label={
                            labels?.region_label ??
                            t('properties.fields.region')
                        }
                        error={error('region')}
                    >
                        <Input
                            id={`${id}-region`}
                            autoComplete="address-level1"
                            maxLength={50}
                            value={value.region}
                            onChange={(e) =>
                                onChange({
                                    region: e.target.value,
                                    ...clearedPlace,
                                })
                            }
                        />
                    </FormField>
                    <FormField
                        id={`${id}-postal`}
                        label={
                            labels?.postal_label ??
                            t('properties.fields.postal_code')
                        }
                        error={error('postal_code')}
                    >
                        <Input
                            id={`${id}-postal`}
                            autoComplete="postal-code"
                            maxLength={20}
                            value={value.postal_code}
                            onChange={(e) =>
                                onChange({
                                    postal_code: e.target.value,
                                    ...clearedPlace,
                                })
                            }
                        />
                    </FormField>
                </div>
            ) : (
                <button
                    type="button"
                    className="flex min-h-11 items-center gap-1.5 text-sm font-medium text-link"
                    onClick={() => setOpen(true)}
                >
                    <MapPin className="size-4" />
                    {t('jobs.quick.enter_manually')}
                </button>
            )}
            {!open && <InputError message={error('city')} />}
        </div>
    );
}
