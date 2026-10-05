import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Plus, TriangleAlert, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import {
    CustomerAvatar,
    useNameAvatar,
} from '@/components/customers/customer-avatar';
import type { AvatarStyle } from '@/components/customers/customer-avatar';
import { PropertyFields } from '@/components/customers/property-fields';
import { emptyProperty } from '@/components/customers/types';
import type { PropertyFormData } from '@/components/customers/types';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { formatPhone } from '@/lib/phone';
import { duplicates, index, show, store, update } from '@/routes/customers';
import type { Option } from '@/types';

type Phone = {
    id?: number;
    label: string;
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
    phones: Phone[];
    emails: Email[];
};

type FormData = {
    type: string;
    first_name: string;
    avatar_style: AvatarStyle;
    last_name: string;
    company_name: string;
    lead_source: string;
    payment_terms: string;
    tags: string;
    notes: string;
    phones: Phone[];
    emails: Email[];
    add_property: boolean;
    property: PropertyFormData;
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
        tags: customer?.tags.join(', ') ?? '',
        notes: customer?.notes ?? '',
        phones: customer?.phones.map(({ id, label, number, is_primary }) => ({
            id,
            label,
            number: formatPhone(number, auth.company?.country ?? 'US'),
            is_primary,
        })) ?? [{ label: 'mobile', number: '', is_primary: true }],
        emails: customer?.emails.map(({ id, label, email, is_primary }) => ({
            id,
            label,
            email,
            is_primary,
        })) ?? [{ label: 'personal', email: '', is_primary: true }],
        add_property: customer === null,
        property: {
            ...emptyProperty(auth.company?.country ?? 'US'),
            is_primary: true,
        },
    });
    const suggestedIcon = useNameAvatar(
        form.data.type === 'residential' ? form.data.first_name : '',
        form.data.avatar_style,
    );
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
            tags: data.tags
                .split(',')
                .map((tag) => tag.trim())
                .filter(Boolean),
        }));

        if (customer) {
            form.put(update(customer.id).url, { preserveScroll: true });
        } else {
            form.post(store().url);
        }
    };

    const isBusiness = form.data.type !== 'residential';
    const title = customer ? customer.display_name : t('customers.add');

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

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="max-w-2xl space-y-8 p-4">
                <PageHeader title={title} />

                <section className="grid gap-4 sm:grid-cols-2">
                    <h2 className="text-base font-medium sm:col-span-2">
                        {t('customers.sections.details')}
                    </h2>
                    <div className="flex items-start gap-3 sm:col-span-2">
                        <CustomerAvatar
                            icon={
                                form.data.type === 'residential'
                                    ? suggestedIcon
                                    : 'business'
                            }
                        />
                        {form.data.type === 'residential' && (
                            <FormField
                                id="avatar_style"
                                label={t('customers.fields.avatar_style')}
                                hint={t('customers.avatar_hint')}
                                error={errors.avatar_style}
                            >
                                <div
                                    id="avatar_style"
                                    role="radiogroup"
                                    className="flex flex-wrap gap-2"
                                >
                                    {(
                                        [
                                            'man',
                                            'woman',
                                            'neutral',
                                            'auto',
                                        ] as const
                                    ).map((style) => (
                                        <button
                                            key={style}
                                            type="button"
                                            role="radio"
                                            aria-checked={
                                                form.data.avatar_style === style
                                            }
                                            onClick={() =>
                                                form.setData(
                                                    'avatar_style',
                                                    style as AvatarStyle,
                                                )
                                            }
                                            className={`min-h-11 rounded-2xl px-4 text-sm font-semibold shadow-sm transition ${
                                                form.data.avatar_style === style
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-card text-foreground ring-1 ring-border'
                                            }`}
                                        >
                                            {t(`customers.icons.${style}`)}
                                        </button>
                                    ))}
                                </div>
                            </FormField>
                        )}
                    </div>
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
                                <option key={source.value} value={source.value}>
                                    {source.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="payment_terms"
                        label={t('customers.fields.payment_terms')}
                        hint={t('customers.payment_terms_hint')}
                        error={errors.payment_terms}
                    >
                        <NativeSelect
                            id="payment_terms"
                            value={form.data.payment_terms}
                            onChange={(e) =>
                                form.setData('payment_terms', e.target.value)
                            }
                        >
                            <option value="">
                                {t('customers.company_default_terms', {
                                    terms: defaultPaymentTerms,
                                })}
                            </option>
                            {paymentTerms.map((terms) => (
                                <option key={terms.value} value={terms.value}>
                                    {terms.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    {isBusiness &&
                        text('company_name', 'organization', 'sm:col-span-2')}
                    {text('first_name', 'given-name')}
                    {text('last_name', 'family-name')}
                </section>

                <section className="grid gap-4">
                    <h2 className="text-base font-medium">
                        {t('customers.sections.contacts')}
                    </h2>

                    {form.data.phones.map((phone, i) => (
                        <div
                            key={phone.id ?? `new-${i}`}
                            className="grid gap-1"
                        >
                            <div className="flex items-end gap-2">
                                <NativeSelect
                                    aria-label={t(
                                        'customers.fields.phone_label',
                                    )}
                                    className="h-9 w-28 shrink-0"
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
                                <Input
                                    type="tel"
                                    inputMode="tel"
                                    autoComplete="tel"
                                    aria-label={t('customers.fields.phone')}
                                    placeholder={t('customers.fields.phone')}
                                    value={phone.number}
                                    onChange={(e) =>
                                        setRow('phones', i, {
                                            number: e.target.value,
                                        })
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
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="justify-self-start"
                        onClick={() =>
                            form.setData('phones', [
                                ...form.data.phones,
                                {
                                    label: 'mobile',
                                    number: '',
                                    is_primary: form.data.phones.length === 0,
                                },
                            ])
                        }
                    >
                        <Plus /> {t('customers.add_phone')}
                    </Button>

                    {form.data.emails.map((email, i) => (
                        <div
                            key={email.id ?? `new-${i}`}
                            className="grid gap-1"
                        >
                            <div className="flex items-end gap-2">
                                <NativeSelect
                                    aria-label={t(
                                        'customers.fields.email_label',
                                    )}
                                    className="h-9 w-28 shrink-0"
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
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="justify-self-start"
                        onClick={() =>
                            form.setData('emails', [
                                ...form.data.emails,
                                {
                                    label: 'personal',
                                    email: '',
                                    is_primary: form.data.emails.length === 0,
                                },
                            ])
                        }
                    >
                        <Plus /> {t('customers.add_email')}
                    </Button>

                    {found.length > 0 && (
                        <Alert>
                            <TriangleAlert />
                            <AlertTitle>
                                {t('customers.duplicate_title')}
                            </AlertTitle>
                            <AlertDescription>
                                <p>{t('customers.duplicate_text')}</p>
                                <ul className="mt-1 space-y-1">
                                    {found.map((d) => (
                                        <li key={d.id}>
                                            <Link
                                                href={show(d.id)}
                                                className="font-medium underline underline-offset-4"
                                            >
                                                {d.display_name}
                                            </Link>{' '}
                                            · {d.matches.join(', ')}
                                        </li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}
                </section>

                {!customer && (
                    <section className="grid gap-4">
                        <label className="flex min-h-9 items-center gap-2 text-base font-medium">
                            <Checkbox
                                checked={form.data.add_property}
                                onCheckedChange={(c) =>
                                    form.setData('add_property', c === true)
                                }
                            />
                            {t('customers.add_first_property')}
                        </label>
                        {form.data.add_property && (
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
                            />
                        )}
                    </section>
                )}

                <section className="grid gap-4">
                    <h2 className="text-base font-medium">
                        {t('customers.sections.other')}
                    </h2>
                    <FormField
                        id="tags"
                        label={t('customers.fields.tags')}
                        error={errors.tags ?? errors['tags.0']}
                        hint={t('customers.tags_hint')}
                    >
                        <Input
                            id="tags"
                            list="customer-tags"
                            value={form.data.tags}
                            onChange={(e) =>
                                form.setData('tags', e.target.value)
                            }
                        />
                        <datalist id="customer-tags">
                            {tags.map((tag) => (
                                <option key={tag} value={tag} />
                            ))}
                        </datalist>
                    </FormField>
                    <FormField
                        id="notes"
                        label={t('customers.fields.notes')}
                        hint={t('customers.about_hint')}
                        error={errors.notes}
                    >
                        <Textarea
                            id="notes"
                            rows={3}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </FormField>
                </section>

                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" asChild>
                        <Link href={customer ? show(customer.id) : index()}>
                            {t('common.cancel')}
                        </Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </>
    );
}

function PrimaryToggle({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: () => void;
}) {
    const t = useTrans();

    return (
        <label
            className="flex h-9 shrink-0 items-center gap-1 text-xs"
            title={t('customers.primary')}
        >
            <input
                type="radio"
                checked={checked}
                onChange={onChange}
                className="size-4"
            />
            <span className="hidden sm:inline">
                {t('customers.make_primary')}
            </span>
        </label>
    );
}

function RemoveButton({ onClick }: { onClick: () => void }) {
    const t = useTrans();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-9 shrink-0"
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
