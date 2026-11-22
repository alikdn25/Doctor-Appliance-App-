import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { PhoneInput } from '@/components/ui/phone-input';
import { useTrans } from '@/lib/i18n';

export type Address = {
    line1: string;
    unit: string;
    city: string;
    region: string;
    postal_code: string;
};

export type CustomerDetails = {
    phone: string;
    first_name: string;
    last_name: string;
    email: string;
    property: Address;
    billing_same: boolean;
    billing: Address;
};

export const emptyAddress = (): Address => ({
    line1: '',
    unit: '',
    city: '',
    region: '',
    postal_code: '',
});

export const emptyCustomer = (): CustomerDetails => ({
    phone: '',
    first_name: '',
    last_name: '',
    email: '',
    property: emptyAddress(),
    billing_same: true,
    billing: emptyAddress(),
});

function AddressFields({
    prefix,
    value,
    onChange,
    errors,
}: {
    prefix: string;
    value: Address;
    onChange: (value: Address) => void;
    errors: Record<string, string>;
}) {
    const t = useTrans();
    const field = (key: keyof Address, className = '') => (
        <label className={`grid gap-1 text-sm ${className}`}>
            <span className="text-muted-foreground">
                {t(`charges.fields.${key}`)}
            </span>
            <Input
                value={value[key]}
                autoComplete={key === 'line1' ? 'address-line1' : undefined}
                onChange={(e) => onChange({ ...value, [key]: e.target.value })}
                aria-invalid={!!errors[`${prefix}.${key}`]}
                data-test={`${prefix}-${key}`}
            />
        </label>
    );

    return (
        <div className="grid grid-cols-3 gap-2">
            {field('line1', 'col-span-2')}
            {field('unit')}
            {field('city', 'col-span-3 sm:col-span-1')}
            {field('region')}
            {field('postal_code', 'col-span-2 sm:col-span-1')}
        </div>
    );
}

/**
 * The customer's details on New charge and on the Paid screen: phone first, first and last name, email, property
 * address, billing address with "same as property".
 */
export function CustomerFields({
    value,
    onChange,
    country,
    errors = {},
    onPhoneBlur,
}: {
    value: CustomerDetails;
    onChange: (value: CustomerDetails) => void;
    country?: string;
    errors?: Record<string, string>;
    onPhoneBlur?: () => void;
}) {
    const t = useTrans();
    const set = <K extends keyof CustomerDetails>(
        key: K,
        v: CustomerDetails[K],
    ) => onChange({ ...value, [key]: v });

    return (
        <div className="grid gap-3" data-test="customer-fields">
            <label className="grid gap-1 text-sm">
                <span className="text-muted-foreground">
                    {t('charges.fields.phone')}
                </span>
                <PhoneInput
                    value={value.phone}
                    country={country}
                    onChange={(v) => set('phone', v)}
                    onBlur={onPhoneBlur}
                    aria-invalid={!!errors.phone}
                    data-test="customer-phone"
                />
                {errors.phone && (
                    <span className="text-xs text-danger">{errors.phone}</span>
                )}
            </label>
            <div className="grid grid-cols-2 gap-2">
                <label className="grid gap-1 text-sm">
                    <span className="text-muted-foreground">
                        {t('charges.fields.first_name')}
                    </span>
                    <Input
                        value={value.first_name}
                        autoComplete="given-name"
                        onChange={(e) => set('first_name', e.target.value)}
                        data-test="customer-first-name"
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    <span className="text-muted-foreground">
                        {t('charges.fields.last_name')}
                    </span>
                    <Input
                        value={value.last_name}
                        autoComplete="family-name"
                        onChange={(e) => set('last_name', e.target.value)}
                        data-test="customer-last-name"
                    />
                </label>
            </div>
            <label className="grid gap-1 text-sm">
                <span className="text-muted-foreground">
                    {t('charges.fields.email')}
                </span>
                <Input
                    type="email"
                    inputMode="email"
                    autoComplete="email"
                    value={value.email}
                    onChange={(e) => set('email', e.target.value)}
                    aria-invalid={!!errors.email}
                    data-test="customer-email"
                />
                {errors.email && (
                    <span className="text-xs text-danger">{errors.email}</span>
                )}
            </label>
            <p className="text-sm font-medium">{t('charges.fields.property')}</p>
            <AddressFields
                prefix="property"
                value={value.property}
                onChange={(v) => set('property', v)}
                errors={errors}
            />
            <label className="flex min-h-10 items-center gap-2 text-sm">
                <Checkbox
                    checked={value.billing_same}
                    onCheckedChange={(c) => set('billing_same', c === true)}
                    data-test="customer-billing-same"
                />
                {t('charges.fields.billing_same')}
            </label>
            {!value.billing_same && (
                <>
                    <p className="text-sm font-medium">
                        {t('charges.fields.billing')}
                    </p>
                    <AddressFields
                        prefix="billing"
                        value={value.billing}
                        onChange={(v) => set('billing', v)}
                        errors={errors}
                    />
                </>
            )}
        </div>
    );
}
