import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Info,
    Plus,
    Star,
    TriangleAlert,
    UserPlus,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import {
    CustomerAvatar,
    useNameAvatar,
} from '@/components/customers/customer-avatar';
import type { AvatarStyle } from '@/components/customers/customer-avatar';
import { PropertyFields } from '@/components/customers/property-fields';
import { emptyProperty, propertyToForm } from '@/components/customers/types';
import type {
    PropertyData,
    PropertyFormData,
} from '@/components/customers/types';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { PhoneInput } from '@/components/ui/phone-input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { TagInput } from '@/components/ui/tag-input';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { formatPhone } from '@/lib/phone';
import { cn } from '@/lib/utils';
import { duplicates, index, show, store, update } from '@/routes/customers';
import type { Option } from '@/types';

type Phone = {
    id?: number;
    label: string;
    contact_name: string;
    number: string;
    is_primary: boolean;
};
type Email = { id?: number; label: string; email: string; is_primary: boolean };

type Customer = {
    id: number;
    type: string;
    first_name: string | null;
    avatar_style: AvatarStyle;
    last_name: string | null;
    company_name: string | null;
    display_name: string;
    lead_source: string | null;
    payment_terms: string | null;
    tags: string[];
    notes: string | null;
    phones: (Omit<Phone, 'contact_name'> & { contact_name: string | null })[];
    emails: Email[];
    /** The main address, edited in place (null when the customer has none). */
    property?: PropertyData | null;
};

type FormData = {
    type: string;
    first_name: string;
    avatar_style: AvatarStyle;
    last_name: string;
    company_name: string;
    lead_source: string;
    payment_terms: string;
    tags: string[];
    notes: string;
    phones: Phone[];
    emails: Email[];
    add_property: boolean;
    property: PropertyFormData & { id?: number };
};

type Duplicate = { id: number; display_name: string; matches: string[] };

type Props = {
    customer: Customer | null;
    types: Option[];
    leadSources: Option[];
    paymentTerms: Option[];
    defaultPaymentTerms: string;
    phoneLabels: Option[];
    emailLabels: Option[];
    tags: string[];
    countries?: Option[];
};

/** Warns about existing customers with the same phone or email. Never blocks saving. */
function useDuplicates(phones: string[], emails: string[], ignore?: number) {
    const [found, setFound] = useState<Duplicate[]>([]);
    const key = JSON.stringify([phones, emails]);

    useEffect(() => {
        const [p, e] = JSON.parse(key) as [string[], string[]];
        const params = new URLSearchParams();
        p.filter((n) => n.replace(/\D/g, '').length >= 7).forEach((n) =>
            params.append('phones[]', n),
        );
        e.filter((m) => m.includes('@')).forEach((m) =>
            params.append('emails[]', m),
        );

        if (ignore) {
            params.append('ignore', String(ignore));
        }

        if (!params.has('phones[]') && !params.has('emails[]')) {
            setFound([]);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`${duplicates().url}?${params}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { duplicates: [] }))
                .then((json: { duplicates: Duplicate[] }) =>
                    setFound(json.duplicates),
                )
                .catch(() => undefined);
        }, 400);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [key, ignore]);

    return found;
}

export default function CustomerForm({
    customer,
    types,
    leadSources,
    paymentTerms,
    defaultPaymentTerms,
    phoneLabels,
    emailLabels,
    tags,
    countries = [],
}: Props) {
    const t = useTrans();
    const { auth } = usePage().props;

    const form = useForm<FormData>({
        type: customer?.type ?? 'residential',
        first_name: customer?.first_name ?? '',
        avatar_style: customer?.avatar_style ?? 'auto',
        last_name: customer?.last_name ?? '',
        company_name: customer?.company_name ?? '',
        lead_source: customer?.lead_source ?? '',
        payment_terms: customer?.payment_terms ?? '',
        tags: customer?.tags ?? [],
        notes: customer?.notes ?? '',
        phones: customer?.phones.map(
            ({ id, label, contact_name, number, is_primary }) => ({
                id,
                label,
                contact_name: contact_name ?? '',
                number: formatPhone(number, auth.company?.country ?? 'US'),
                is_primary,
            }),
        ) ?? [
            { label: 'mobile', contact_name: '', number: '', is_primary: true },
        ],
        emails: customer?.emails.map(({ id, label, email, is_primary }) => ({
            id,
            label,
            email,
            is_primary,
        })) ?? [{ label: 'personal', email: '', is_primary: true }],
        add_property: true,
        property: customer?.property
            ? {
                  ...propertyToForm(customer.property),
                  id: customer.property.id,
              }
            : {
                  ...emptyProperty(auth.company?.country ?? 'US'),
                  is_primary: true,
              },
    });
    const nameIcon = useNameAvatar(
        form.data.type === 'residential' ? form.data.first_name : '',
        form.data.avatar_style,
    );
    // A second named person on a phone makes the automatic icon a couple.
    const secondPerson = form.data.phones.some(
        (p) =>
            p.contact_name.trim() !== '' &&
            p.contact_name
                .trim()
                .split(/[\s·,(]/)[0]
                .toLowerCase() !== form.data.first_name.trim().toLowerCase(),
    );
    const suggestedIcon =
        form.data.avatar_style === 'auto' && secondPerson ? 'couple' : nameIcon;
    const errors = form.errors as Record<string, string | undefined>;

    const found = useDuplicates(
        form.data.phones.map((p) => p.number),
        form.data.emails.map((e) => e.email),
        customer?.id,
    );

    const setRow = (
        list: 'phones' | 'emails',
        i: number,
        patch: Partial<Phone & Email>,
    ) =>
        form.setData(
            list,
            (form.data[list] as (Phone | Email)[]).map((row, j) =>
                j === i
                    ? { ...row, ...patch }
                    : patch.is_primary
                      ? { ...row, is_primary: false }
                      : row,
            ) as never,
        );

    const removeRow = (list: 'phones' | 'emails', i: number) =>
        form.setData(
            list,
            (form.data[list] as (Phone | Email)[]).filter(
                (_, j) => j !== i,
            ) as never,
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            phones: data.phones.filter((p) => p.number.trim() !== ''),
            emails: data.emails.filter((m) => m.email.trim() !== ''),
            // No address typed: the customer is saved without one (it can be added later); an address is never
            // saved empty.
            add_property:
                data.add_property && data.property.line1.trim() !== '',
        }));

        if (customer) {
            form.put(update(customer.id).url, { preserveScroll: true });
        } else {
            form.post(store().url);
        }
    };

    const isBusiness = form.data.type !== 'residential';
    const title = customer ? customer.display_name : t('customers.add');
    const country = auth.company?.country ?? 'US';
    const [iconOpen, setIconOpen] = useState(false);
    const [iconHint, setIconHint] = useState(false);
    const addPhone = () =>
        form.setData('phones', [
            ...form.data.phones,
            {
                label: 'mobile',
                contact_name: '',
                number: '',
                is_primary: form.data.phones.length === 0,
            },
        ]);

    const text = (
        field: 'first_name' | 'last_name' | 'company_name',
        autoComplete: string,
        className = '',
    ) => (
        <FormField
            id={field}
            label={t(`customers.fields.${field}`)}
            error={errors[field]}
            className={className}
        >
            <Input
                id={field}
                value={form.data[field]}
                autoComplete={autoComplete}
                onChange={(e) => form.setData(field, e.target.value)}
            />
        </FormField>
    );

    // "Already a customer: Jane Cooper [Open]" right where the phone or email is typed (SPEC §7.1).
    const duplicateNote = found.length > 0 && (
        <div
            role="status"
            data-test="customer-duplicate"
            className="flex flex-wrap items-center gap-2 rounded-2xl bg-amber-50 p-3 text-sm text-amber-950"
        >
            <TriangleAlert className="size-4 shrink-0" />
            {found.map((d) => (
                <span key={d.id} className="flex flex-wrap items-center gap-2">
                    {t('customers.already_customer', { name: d.display_name })}
                    <Button asChild size="sm" variant="tertiary">
                        <Link href={show(d.id)}>{t('customers.open')}</Link>
                    </Button>
                </span>
            ))}
        </div>
    );

    return (
        <>
            <Head title={title} />
            <ScreenHeader
                title={title}
                back={customer ? show(customer.id) : index()}
            />

            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-6 p-4 sm:p-6"
                data-test="customer-form"
            >
                {/* 1. Name, with the icon on the left (tap to choose). */}
                <section className="grid gap-4 sm:grid-cols-2">
                    <div className="flex items-center gap-3 sm:col-span-2">
                        <button
                            type="button"
                            className="da-press rounded-full"
                            aria-label={t('customers.fields.avatar_style')}
                            onClick={() => setIconOpen(true)}
                            disabled={form.data.type !== 'residential'}
                            data-test="avatar-button"
                        >
                            <CustomerAvatar
                                size="lg"
                                icon={
                                    form.data.type === 'residential'
                                        ? suggestedIcon
                                        : 'business'
                                }
                            />
                        </button>
                        <p className="text-sm text-muted-foreground">
                            {form.data.type === 'residential'
                                ? t('customers.icon_tap')
                                : form.data.type !== 'residential' &&
                                  types.find((o) => o.value === form.data.type)
                                      ?.label}
                        </p>
                    </div>
                    {isBusiness &&
                        text('company_name', 'organization', 'sm:col-span-2')}
                    {text('first_name', 'given-name')}
                    {text('last_name', 'family-name')}
                </section>

                {/* 2. Phones and emails. */}
                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">
                        {t('customers.sections.contacts')}
                    </h2>

                    {form.data.phones.map((phone, i) => (
                        <div
                            key={phone.id ?? `new-${i}`}
                            className="grid gap-1"
                        >
                            <div className="flex items-center gap-2">
                                <NativeSelect
                                    aria-label={t(
                                        'customers.fields.phone_label',
                                    )}
                                    className="w-32 shrink-0"
                                    value={phone.label}
                                    onChange={(e) =>
                                        setRow('phones', i, {
                                            label: e.target.value,
                                        })
                                    }
                                >
                                    {phoneLabels.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <PhoneInput
                                    aria-label={t('customers.fields.phone')}
                                    placeholder={t('customers.fields.phone')}
                                    country={country}
                                    className="min-w-0 flex-1"
                                    value={phone.number}
                                    onChange={(number) =>
                                        setRow('phones', i, { number })
                                    }
                                />
                                <PrimaryToggle
                                    checked={phone.is_primary}
                                    onChange={() =>
                                        setRow('phones', i, {
                                            is_primary: true,
                                        })
                                    }
                                />
                                <RemoveButton
                                    onClick={() => removeRow('phones', i)}
                                />
                            </div>
                            <InputError
                                message={errors[`phones.${i}.number`]}
                            />
                            {/* Whose number it is when it is not the customer: a second person (couple icon). */}
                            {(i > 0 || phone.contact_name !== '') && (
                                <Input
                                    aria-label={t(
                                        'customers.fields.contact_name',
                                    )}
                                    placeholder={t(
                                        'customers.contact_name_placeholder',
                                    )}
                                    maxLength={100}
                                    value={phone.contact_name}
                                    onChange={(e) =>
                                        setRow('phones', i, {
                                            contact_name: e.target.value,
                                        })
                                    }
                                />
                            )}
                            <InputError
                                message={errors[`phones.${i}.contact_name`]}
                            />
                        </div>
                    ))}

                    {form.data.emails.map((email, i) => (
                        <div
                            key={email.id ?? `new-${i}`}
                            className="grid gap-1"
                        >
                            <div className="flex items-center gap-2">
                                <NativeSelect
                                    aria-label={t(
                                        'customers.fields.email_label',
                                    )}
                                    className="w-32 shrink-0"
                                    value={email.label}
                                    onChange={(e) =>
                                        setRow('emails', i, {
                                            label: e.target.value,
                                        })
                                    }
                                >
                                    {emailLabels.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <Input
                                    type="email"
                                    autoComplete="email"
                                    className="min-w-0 flex-1"
                                    aria-label={t('customers.fields.email')}
                                    placeholder={t('customers.fields.email')}
                                    value={email.email}
                                    onChange={(e) =>
                                        setRow('emails', i, {
                                            email: e.target.value,
                                        })
                                    }
                                />
                                <PrimaryToggle
                                    checked={email.is_primary}
                                    onChange={() =>
                                        setRow('emails', i, {
                                            is_primary: true,
                                        })
                                    }
                                />
                                <RemoveButton
                                    onClick={() => removeRow('emails', i)}
                                />
                            </div>
                            <InputError message={errors[`emails.${i}.email`]} />
                        </div>
                    ))}

                    {duplicateNote}

                    <div className="flex flex-wrap gap-x-4">
                        <Button
                            type="button"
                            variant="link"
                            className="h-11 px-0"
                            onClick={addPhone}
                        >
                            <Plus /> {t('customers.add_phone')}
                        </Button>
                        <Button
                            type="button"
                            variant="link"
                            className="h-11 px-0"
                            onClick={() =>
                                form.setData('emails', [
                                    ...form.data.emails,
                                    {
                                        label: 'personal',
                                        email: '',
                                        is_primary:
                                            form.data.emails.length === 0,
                                    },
                                ])
                            }
                        >
                            <Plus /> {t('customers.add_email')}
                        </Button>
                        {form.data.type === 'residential' && (
                            <Button
                                type="button"
                                variant="link"
                                className="h-11 px-0"
                                onClick={addPhone}
                            >
                                <UserPlus /> {t('customers.add_second_person')}
                            </Button>
                        )}
                    </div>
                </section>

                {/* 3. Service address: one field; the rest opens after a pick. Editing changes the main address. */}
                <section className="grid gap-3" data-test="customer-address">
                    <div className="flex items-baseline justify-between gap-2">
                        <h2 className="text-base font-semibold">
                            {t('customers.service_address')}
                        </h2>
                        <span className="text-xs text-muted-foreground">
                            {t('customers.address_optional')}
                        </span>
                    </div>
                    <PropertyFields
                        data={form.data.property}
                        onChange={(patch) =>
                            form.setData('property', {
                                ...form.data.property,
                                ...patch,
                            })
                        }
                        errors={errors}
                        errorPrefix="property."
                        countries={countries}
                    />
                </section>

                {/* 4. About the customer and tags. */}
                <section className="grid gap-4">
                    <FormField
                        id="notes"
                        label={t('customers.fields.notes')}
                        hint={t('customers.about_hint')}
                        error={errors.notes}
                    >
                        <Textarea
                            id="notes"
                            rows={2}
                            className="[field-sizing:content]"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="tags"
                        label={t('customers.fields.tags')}
                        error={errors.tags ?? errors['tags.0']}
                    >
                        <TagInput
                            id="tags"
                            value={form.data.tags}
                            suggestions={tags}
                            onChange={(value) => form.setData('tags', value)}
                        />
                    </FormField>
                </section>

                {/* 5. More: type, source, payment terms. */}
                <details
                    className="da-card group"
                    data-test="customer-more"
                    open={
                        !!(
                            errors.type ||
                            errors.lead_source ||
                            errors.payment_terms
                        ) || undefined
                    }
                >
                    <summary className="flex min-h-12 cursor-pointer list-none items-center gap-2 px-4 text-sm font-semibold [&::-webkit-details-marker]:hidden">
                        <span className="flex-1">{t('customers.more')}</span>
                        <ChevronDown className="size-5 transition-transform group-open:rotate-180" />
                    </summary>
                    <div className="grid gap-4 border-t p-4 sm:grid-cols-2">
                        <FormField
                            id="type"
                            label={t('customers.fields.type')}
                            error={errors.type}
                        >
                            <NativeSelect
                                id="type"
                                value={form.data.type}
                                onChange={(e) =>
                                    form.setData('type', e.target.value)
                                }
                            >
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="lead_source"
                            label={t('customers.fields.lead_source')}
                            error={errors.lead_source}
                        >
                            <NativeSelect
                                id="lead_source"
                                value={form.data.lead_source}
                                onChange={(e) =>
                                    form.setData('lead_source', e.target.value)
                                }
                            >
                                <option value="">
                                    {t('customers.lead_sources.none')}
                                </option>
                                {leadSources.map((source) => (
                                    <option
                                        key={source.value}
                                        value={source.value}
                                    >
                                        {source.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="payment_terms"
                            label={t('customers.fields.payment_terms')}
                            hint={t('customers.payment_terms_hint_short')}
                            error={errors.payment_terms}
                            className="sm:col-span-2"
                        >
                            <NativeSelect
                                id="payment_terms"
                                value={form.data.payment_terms}
                                onChange={(e) =>
                                    form.setData(
                                        'payment_terms',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    {t('customers.company_default_terms', {
                                        terms: defaultPaymentTerms,
                                    })}
                                </option>
                                {paymentTerms.map((terms) => (
                                    <option
                                        key={terms.value}
                                        value={terms.value}
                                    >
                                        {terms.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
                </details>

                <StickyActionBar>
                    <Button
                        type="button"
                        variant="ghost"
                        size="lg"
                        className="md:order-first"
                        onClick={() =>
                            router.visit(
                                (customer ? show(customer.id) : index()).url,
                            )
                        }
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="submit"
                        size="lg"
                        loading={form.processing}
                        data-test="customer-save"
                    >
                        {t('common.save')}
                    </Button>
                </StickyActionBar>
            </form>

            {/* The icon: Man / Woman / Couple / Automatic, with the explanation behind (i). */}
            <Sheet open={iconOpen} onOpenChange={setIconOpen}>
                <SheetContent side="bottom" className="gap-2 px-3 pt-4">
                    <SheetHeader className="p-0 px-2 pr-12">
                        <SheetTitle className="flex items-center gap-1">
                            {t('customers.fields.avatar_style')}
                            <button
                                type="button"
                                aria-label={t('customers.avatar_hint')}
                                aria-expanded={iconHint}
                                className="flex size-8 items-center justify-center rounded-full text-muted-foreground"
                                onClick={() => setIconHint(!iconHint)}
                            >
                                <Info className="size-4" />
                            </button>
                        </SheetTitle>
                        <SheetDescription
                            className={iconHint ? undefined : 'sr-only'}
                        >
                            {t('customers.avatar_hint')}
                        </SheetDescription>
                    </SheetHeader>
                    <div
                        role="radiogroup"
                        className="grid grid-cols-4 gap-2 pb-[env(safe-area-inset-bottom)]"
                    >
                        {(['man', 'woman', 'couple', 'auto'] as const).map(
                            (style) => (
                                <button
                                    key={style}
                                    type="button"
                                    role="radio"
                                    aria-checked={
                                        form.data.avatar_style === style
                                    }
                                    aria-pressed={
                                        form.data.avatar_style === style
                                    }
                                    onClick={() => {
                                        form.setData(
                                            'avatar_style',
                                            style as AvatarStyle,
                                        );
                                        setIconOpen(false);
                                    }}
                                    className="da-chip da-press flex min-h-20 flex-col items-center justify-center gap-1 rounded-2xl text-sm font-semibold"
                                >
                                    <span aria-hidden="true">
                                        <CustomerAvatar
                                            icon={
                                                style === 'auto'
                                                    ? nameIcon
                                                    : style
                                            }
                                        />
                                    </span>
                                    {t(`customers.icons.${style}`)}
                                </button>
                            ),
                        )}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}

/** The primary phone or email: a star with "Primary" (UX-REDESIGN §8.3). */
function PrimaryToggle({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: () => void;
}) {
    const t = useTrans();

    return (
        <button
            type="button"
            aria-pressed={checked}
            aria-label={t('customers.make_primary')}
            title={t('customers.primary')}
            onClick={onChange}
            className={cn(
                'flex h-11 shrink-0 items-center gap-1 rounded-xl px-1.5 text-xs font-medium',
                checked ? 'text-amber-600' : 'text-muted-foreground',
            )}
        >
            <Star className={cn('size-5', checked && 'fill-current')} />
            <span className="hidden sm:inline">
                {checked ? t('customers.primary') : t('customers.make_primary')}
            </span>
        </button>
    );
}

function RemoveButton({ onClick }: { onClick: () => void }) {
    const t = useTrans();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-11 shrink-0"
            aria-label={t('common.remove')}
            onClick={onClick}
        >
            <X />
        </Button>
    );
}

CustomerForm.layout = {
    breadcrumbs: [{ title: 'customers.title', href: index() }],
};
