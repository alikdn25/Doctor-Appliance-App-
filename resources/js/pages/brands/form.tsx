import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { formatPhone } from '@/lib/phone';
import { destroy, index, store, update } from '@/routes/brands';

type Address = {
    id?: number;
    label: string;
    line1: string;
    line2: string;
    city: string;
    region: string;
    postal_code: string;
    country: string;
    is_primary: boolean;
};

type Brand = {
    id: number;
    name: string;
    logo_url: string | null;
    primary_color: string | null;
    secondary_color: string | null;
    website: string | null;
    email: string | null;
    phone: string | null;
    sender_name: string | null;
    sender_email: string | null;
    tax_number: string | null;
    business_number: string | null;
    invoice_footer: string | null;
    invoice_terms: string | null;
    google_profile_id: number | null;
    is_active: boolean;
    addresses: (Omit<Address, 'label' | 'line2' | 'region' | 'postal_code'> & {
        id: number;
        label: string | null;
        line2: string | null;
        region: string | null;
        postal_code: string | null;
    })[];
};

type FormData = {
    name: string;
    primary_color: string;
    secondary_color: string;
    website: string;
    email: string;
    phone: string;
    sender_name: string;
    sender_email: string;
    tax_number: string;
    business_number: string;
    invoice_footer: string;
    invoice_terms: string;
    google_profile_id: string;
    is_active: boolean;
    logo: File | null;
    remove_logo: boolean;
    addresses: Address[];
};

const emptyAddress = (primary: boolean, country: string): Address => ({
    label: '',
    line1: '',
    line2: '',
    city: '',
    region: '',
    postal_code: '',
    country,
    is_primary: primary,
});

export default function BrandForm({
    brand,
    canUpdate = true,
}: {
    brand: Brand | null;
    canUpdate?: boolean;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const country = auth.company?.country ?? 'US';
    const readOnly = brand !== null && !canUpdate;

    const form = useForm<FormData>({
        name: brand?.name ?? '',
        primary_color: brand?.primary_color ?? '#0E7490',
        secondary_color: brand?.secondary_color ?? '',
        website: brand?.website ?? '',
        email: brand?.email ?? '',
        phone: formatPhone(brand?.phone, country),
        sender_name: brand?.sender_name ?? '',
        sender_email: brand?.sender_email ?? '',
        tax_number: brand?.tax_number ?? '',
        business_number: brand?.business_number ?? '',
        invoice_footer: brand?.invoice_footer ?? '',
        invoice_terms: brand?.invoice_terms ?? '',
        google_profile_id: brand?.google_profile_id
            ? String(brand.google_profile_id)
            : '',
        is_active: brand?.is_active ?? true,
        logo: null,
        remove_logo: false,
        addresses: brand?.addresses.map((a) => ({
            id: a.id,
            label: a.label ?? '',
            line1: a.line1,
            line2: a.line2 ?? '',
            city: a.city,
            region: a.region ?? '',
            postal_code: a.postal_code ?? '',
            country: a.country,
            is_primary: a.is_primary,
        })) ?? [emptyAddress(true, country)],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const text = (
        field: Exclude<
            keyof FormData,
            'is_active' | 'logo' | 'remove_logo' | 'addresses'
        >,
        type = 'text',
    ) => (
        <FormField
            id={field}
            label={t(`brands.fields.${field}`)}
            error={errors[field]}
        >
            <Input
                id={field}
                type={type}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
                disabled={readOnly}
            />
        </FormField>
    );

    const setAddress = (i: number, patch: Partial<Address>) =>
        form.setData(
            'addresses',
            form.data.addresses.map((a, j) =>
                j === i
                    ? { ...a, ...patch }
                    : patch.is_primary
                      ? { ...a, is_primary: false }
                      : a,
            ),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (brand) {
            // Multipart forms are sent as POST with method spoofing.
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(update(brand.id).url, { preserveScroll: true });
        } else {
            form.post(store().url);
        }
    };

    const remove = () => {
        if (
            brand &&
            confirm(t('brands.confirm_delete', { name: brand.name }))
        ) {
            router.delete(destroy(brand.id).url);
        }
    };

    return (
        <>
            <Head title={brand ? brand.name : t('brands.add')} />

            <form onSubmit={submit} className="max-w-2xl space-y-8 p-4">
                <PageHeader
                    title={brand ? brand.name : t('brands.add')}
                    description={t('brands.form_description')}
                />

                <section className="grid gap-4">
                    {text('name')}

                    <div className="flex items-center gap-4">
                        {brand?.logo_url && !form.data.remove_logo && (
                            <img
                                src={brand.logo_url}
                                alt={t('brands.fields.logo')}
                                className="size-16 rounded-md border object-contain"
                            />
                        )}
                        <FormField
                            id="logo"
                            label={t('brands.fields.logo')}
                            error={errors.logo}
                            hint={t('brands.logo_hint')}
                            className="flex-1"
                        >
                            <Input
                                id="logo"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                disabled={readOnly}
                                onChange={(e) =>
                                    form.setData(
                                        'logo',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    {brand?.logo_url && !readOnly && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.remove_logo}
                                onCheckedChange={(c) =>
                                    form.setData('remove_logo', c === true)
                                }
                            />
                            {t('brands.remove_logo')}
                        </label>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <FormField
                            id="primary_color"
                            label={t('brands.fields.primary_color')}
                            error={errors.primary_color}
                        >
                            <Input
                                id="primary_color"
                                type="color"
                                className="h-11 p-1"
                                value={form.data.primary_color || '#000000'}
                                onChange={(e) =>
                                    form.setData(
                                        'primary_color',
                                        e.target.value,
                                    )
                                }
                                disabled={readOnly}
                            />
                        </FormField>
                        <FormField
                            id="secondary_color"
                            label={t('brands.fields.secondary_color')}
                            error={errors.secondary_color}
                        >
                            <Input
                                id="secondary_color"
                                type="color"
                                className="h-11 p-1"
                                value={form.data.secondary_color || '#ffffff'}
                                onChange={(e) =>
                                    form.setData(
                                        'secondary_color',
                                        e.target.value,
                                    )
                                }
                                disabled={readOnly}
                            />
                        </FormField>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2">
                    <h2 className="text-base font-medium sm:col-span-2">
                        {t('brands.sections.contact')}
                    </h2>
                    {text('phone', 'tel')}
                    {text('email', 'email')}
                    {text('website', 'url')}
                    {text('sender_name')}
                    {text('sender_email', 'email')}
                </section>

                <section className="grid gap-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-medium">
                            {t('brands.sections.addresses')}
                        </h2>
                        {!readOnly && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('addresses', [
                                        ...form.data.addresses,
                                        emptyAddress(
                                            form.data.addresses.length === 0,
                                            country,
                                        ),
                                    ])
                                }
                            >
                                <Plus /> {t('brands.add_address')}
                            </Button>
                        )}
                    </div>

                    {form.data.addresses.map((address, i) => (
                        <fieldset
                            key={address.id ?? `new-${i}`}
                            className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
                            disabled={readOnly}
                        >
                            {(
                                [
                                    ['label', 'sm:col-span-2'],
                                    ['line1', 'sm:col-span-2'],
                                    ['line2', 'sm:col-span-2'],
                                    ['city', ''],
                                    ['region', ''],
                                    ['postal_code', ''],
                                    ['country', ''],
                                ] as const
                            ).map(([field, span]) => (
                                <FormField
                                    key={field}
                                    id={`address-${i}-${field}`}
                                    label={t(`brands.fields.${field}`)}
                                    error={errors[`addresses.${i}.${field}`]}
                                    className={span}
                                >
                                    <Input
                                        id={`address-${i}-${field}`}
                                        value={address[field]}
                                        maxLength={
                                            field === 'country' ? 2 : undefined
                                        }
                                        onChange={(e) =>
                                            setAddress(i, {
                                                [field]:
                                                    field === 'country'
                                                        ? e.target.value.toUpperCase()
                                                        : e.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            ))}
                            <div className="flex items-center justify-between sm:col-span-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={address.is_primary}
                                        onCheckedChange={(c) =>
                                            setAddress(i, {
                                                is_primary: c === true,
                                            })
                                        }
                                    />
                                    {t('brands.primary_address')}
                                </label>
                                {!readOnly && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            form.setData(
                                                'addresses',
                                                form.data.addresses.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 /> {t('common.remove')}
                                    </Button>
                                )}
                            </div>
                        </fieldset>
                    ))}
                </section>

                <section className="grid gap-4">
                    <h2 className="text-base font-medium">
                        {t('brands.sections.documents')}
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {text('tax_number')}
                        {text('business_number')}
                    </div>
                    {(['invoice_footer', 'invoice_terms'] as const).map(
                        (field) => (
                            <FormField
                                key={field}
                                id={field}
                                label={t(`brands.fields.${field}`)}
                                error={errors[field]}
                            >
                                <Textarea
                                    id={field}
                                    rows={3}
                                    value={form.data[field]}
                                    onChange={(e) =>
                                        form.setData(field, e.target.value)
                                    }
                                    disabled={readOnly}
                                />
                            </FormField>
                        ),
                    )}
                </section>

                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={form.data.is_active}
                        disabled={readOnly}
                        onCheckedChange={(c) =>
                            form.setData('is_active', c === true)
                        }
                    />
                    {t('brands.fields.is_active')}
                </label>

                {!readOnly && (
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
                        {brand ? (
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={remove}
                            >
                                <Trash2 /> {t('brands.delete')}
                            </Button>
                        ) : (
                            <span />
                        )}
                        <Button type="submit" disabled={form.processing}>
                            {t('common.save')}
                        </Button>
                    </div>
                )}
            </form>
        </>
    );
}

BrandForm.layout = {
    breadcrumbs: [{ title: 'brands.title', href: index() }],
};
