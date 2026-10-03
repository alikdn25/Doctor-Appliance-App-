import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Plus, Search, Trash2, UserPlus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import {
    AddressAutocomplete,
    clearedPlace,
    GEOCODED_FIELDS,
} from '@/components/customers/address-autocomplete';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import type { ApplianceItem, Assignable } from '@/components/jobs/types';
import { applianceTitle } from '@/components/jobs/types';
import { VisitFields } from '@/components/jobs/visit-fields';
import { CustomerAvatar } from '@/components/customers/customer-avatar';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { CustomerNotes } from '@/components/customers/customer-notes';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { duplicates } from '@/routes/customers';
import { index, lookup, show, store, update } from '@/routes/jobs';
import { warranties as jobWarranties } from '@/routes/jobs';
import type { Option } from '@/types';

type CustomerOption = {
    id: number;
    display_name: string;
    avatar_icon: AvatarIcon;
    notes: string | null;
    phone: string | null;
    lead_source: string | null;
    properties: {
        id: number;
        label: string | null;
        full_address: string;
        is_primary: boolean;
        appliances: ApplianceItem[];
    }[];
    /** Earlier jobs, for a return visit or warranty callback. */
    jobs?: {
        id: number;
        number: number;
        property_id: number;
        status_label: string;
        created_at: string | null;
        appliance_ids: number[];
        appliances: string;
    }[];
};

type BringItem = { description: string; quantity: string };

type JobData = {
    id: number;
    number: number;
    brand_id: number;
    property_id: number;
    job_type: string;
    lead_source: string | null;
    description: string | null;
    notes: string | null;
    appliance_ids: number[];
    visit_type: string;
    previous_job_id: number | null;
    bring_items: BringItem[];
};

type NewAppliance = {
    type: string;
    manufacturer: string;
    model_number: string;
    serial_number: string;
};

type FormData = {
    brand_id: string;
    job_type: string;
    visit_type: string;
    previous_job_id: number | null;
    bring_items: BringItem[];
    lead_source: string;
    description: string;
    notes: string;
    customer_id: number | null;
    property_id: number | null;
    appliance_ids: number[];
    new_appliances: NewAppliance[];
    new_customer_mode: boolean;
    new_customer: {
        first_name: string;
        last_name: string;
        company_name: string;
        phone: string;
        email: string;
        notes: string;
        property: {
            line1: string;
            unit: string;
            city: string;
            region: string;
            postal_code: string;
            country: string;
            gate_code: string;
            google_place_id: string;
            latitude: string;
            longitude: string;
        };
    };
    add_visit: boolean;
    visit: {
        date: string;
        start_time: string;
        end_time: string;
        estimated_duration_minutes: string;
        assignee_ids: number[];
        strict_arrival: boolean;
    };
};

type Props = {
    job: JobData | null;
    customer: CustomerOption | null;
    today: string;
    booking?: boolean;
    bookingDate?: string | null;
    brands: Option[];
    jobTypes: Option[];
    leadSources: Option[];
    applianceTypes: Option[];
    manufacturers: string[];
    assignableUsers: Assignable[];
    visitTypes: Option[];
};

const primaryPropertyId = (c: CustomerOption | null) =>
    (c?.properties.find((p) => p.is_primary) ?? c?.properties[0])?.id ?? null;

export default function JobForm({
    job,
    customer: initialCustomer,
    today,
    booking = false,
    bookingDate,
    brands,
    jobTypes,
    leadSources,
    applianceTypes,
    manufacturers,
    assignableUsers,
    visitTypes,
}: Props) {
    const t = useTrans();
    const time = useCompanyTime();
    const phoneText = usePhone();
    const { auth } = usePage().props;
    const tracksAppliances = auth.company?.tracks_appliances ?? true;
    const editing = job !== null;
    const [customer, setCustomer] = useState<CustomerOption | null>(
        initialCustomer,
    );
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<CustomerOption[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [duplicate, setDuplicate] = useState<{
        display_name: string;
    } | null>(null);

    const form = useForm<FormData>({
        brand_id: String(job?.brand_id ?? brands[0]?.value ?? ''),
        job_type: job?.job_type ?? 'repair',
        visit_type: job?.visit_type ?? 'new_diagnosis',
        previous_job_id: job?.previous_job_id ?? null,
        bring_items: job?.bring_items ?? [],
        lead_source: job?.lead_source ?? initialCustomer?.lead_source ?? '',
        description: job?.description ?? '',
        notes: job?.notes ?? '',
        customer_id: initialCustomer?.id ?? null,
        property_id: job?.property_id ?? primaryPropertyId(initialCustomer),
        appliance_ids: job?.appliance_ids ?? [],
        new_appliances: [],
        new_customer_mode: false,
        new_customer: {
            first_name: '',
            last_name: '',
            company_name: '',
            phone: '',
            email: '',
            notes: '',
            property: {
                line1: '',
                unit: '',
                city: '',
                region: '',
                postal_code: '',
                country: auth.company?.country ?? 'US',
                gate_code: '',
                google_place_id: '',
                latitude: '',
                longitude: '',
            },
        },
        add_visit: !job && booking,
        visit: {
            date: bookingDate ?? today,
            start_time: '09:00',
            end_time: '11:00',
            estimated_duration_minutes: '60',
            assignee_ids: booking && assignableUsers.length === 1 ? [assignableUsers[0].id] : [],
            strict_arrival: false,
        },
    });
    const followsUp = ['return_visit', 'callback'].includes(
        form.data.visit_type,
    );
    const earlierJobs = (customer?.jobs ?? []).filter((j) => j.id !== job?.id);

    // Picking the earlier job brings its appliances along (same address).
    const choosePreviousJob = (id: string) => {
        const previous = earlierJobs.find((j) => String(j.id) === id);
        form.setData((d) => ({
            ...d,
            previous_job_id: previous?.id ?? null,
            appliance_ids:
                previous && previous.property_id === d.property_id
                    ? previous.appliance_ids
                    : d.appliance_ids,
        }));
    };

    // Warranty callback: which warranties of the original job still run on the visit day.
    const [warranties, setWarranties] = useState<{
        date: string;
        number: number;
        lines: {
            item_id: number;
            description: string;
            warranty: string;
            ends_on: string | null;
            active: boolean;
        }[];
    } | null>(null);
    const warrantyDate = form.data.add_visit ? form.data.visit.date : today;

    useEffect(() => {
        if (form.data.visit_type !== 'callback' || !form.data.previous_job_id) {
            setWarranties(null);

            return;
        }

        const controller = new AbortController();
        fetch(
            jobWarranties(form.data.previous_job_id, {
                query: { date: warrantyDate },
            }).url,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then((r) => (r.ok ? r.json() : null))
            .then(setWarranties)
            .catch(() => undefined);

        return () => controller.abort();
    }, [form.data.visit_type, form.data.previous_job_id, warrantyDate]);

    const setBring = (index: number, patch: Partial<BringItem>) =>
        form.setData(
            'bring_items',
            form.data.bring_items.map((item, i) =>
                i === index ? { ...item, ...patch } : item,
            ),
        );
    const errors = form.errors as Record<string, string | undefined>;
    const { data } = form;

    // Customer lookup while typing (name, phone, address, model, serial).
    useEffect(() => {
        const term = search.trim();

        if (term.length < 2) {
            setResults(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            setSearching(true);
            fetch(lookup({ query: { search: term } }).url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { customers: [] }))
                .then((json: { customers: CustomerOption[] }) =>
                    setResults(json.customers),
                )
                .catch(() => undefined)
                .finally(() => setSearching(false));
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [search]);

    // Duplicate warning for a new customer's phone (never blocks saving).
    const newPhone = data.new_customer.phone;
    useEffect(() => {
        if (!data.new_customer_mode || newPhone.replace(/\D/g, '').length < 7) {
            setDuplicate(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(
                `${duplicates().url}?${new URLSearchParams({ 'phones[]': newPhone })}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((r) => (r.ok ? r.json() : { duplicates: [] }))
                .then((json: { duplicates: { display_name: string }[] }) =>
                    setDuplicate(json.duplicates[0] ?? null),
                )
                .catch(() => undefined);
        }, 400);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [newPhone, data.new_customer_mode]);

    const chooseCustomer = (c: CustomerOption) => {
        setCustomer(c);
        setSearch('');
        setResults(null);
        form.setData((d) => ({
            ...d,
            customer_id: c.id,
            property_id: primaryPropertyId(c),
            appliance_ids: [],
            lead_source: d.lead_source || c.lead_source || '',
            new_customer_mode: false,
        }));
    };

    const clearCustomer = () => {
        setCustomer(null);
        form.setData((d) => ({
            ...d,
            customer_id: null,
            property_id: null,
            appliance_ids: [],
        }));
    };

    const setNewCustomer = (patch: Partial<FormData['new_customer']>) =>
        form.setData('new_customer', { ...data.new_customer, ...patch });

    // Typing over a picked address clears its place ID and coordinates.
    const setNewAddress = (
        patch: Partial<FormData['new_customer']['property']>,
        picked = false,
    ) =>
        form.setData('new_customer', {
            ...data.new_customer,
            property: {
                ...data.new_customer.property,
                ...(picked ||
                !data.new_customer.property.google_place_id ||
                !GEOCODED_FIELDS.some((field) => field in patch)
                    ? {}
                    : clearedPlace),
                ...patch,
            },
        });

    const setVisit = (patch: Partial<FormData['visit']>) =>
        form.setData('visit', { ...data.visit, ...patch });

    const toggle = (list: number[], id: number, on: boolean) =>
        on ? [...list, id] : list.filter((x) => x !== id);

    const setNewAppliance = (i: number, patch: Partial<NewAppliance>) =>
        form.setData(
            'new_appliances',
            data.new_appliances.map((a, j) =>
                j === i ? { ...a, ...patch } : a,
            ),
        );

    const property = customer?.properties.find(
        (p) => p.id === data.property_id,
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (job) {
            form.put(update(job.id).url);
        } else {
            form.post(store().url);
        }
    };

    const textField = (
        id: string,
        label: string,
        value: string,
        onChange: (v: string) => void,
        error?: string,
        extra: Record<string, string> = {},
        className = '',
    ) => (
        <FormField id={id} label={label} error={error} className={className}>
            <Input
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                {...extra}
            />
        </FormField>
    );

    const title = job ? t('jobs.edit') + ` #${job.number}` : t(booking ? 'nav.book_customer' : 'jobs.add');

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <PageHeader title={title} />

                {/* Customer */}
                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="text-base font-medium">
                        {t('jobs.sections.customer')}
                    </h2>

                    {customer ? (
                        <div className="flex items-start justify-between gap-2 rounded-md bg-muted/50 p-3">
                            <div className="min-w-0 flex-1 space-y-3">
                                <div className="flex items-center gap-3">
                                    <CustomerAvatar
                                        icon={customer.avatar_icon}
                                    />
                                    <span className="font-medium">
                                        {customer.display_name}
                                    </span>
                                </div>
                                {customer.phone && (
                                    <div className="text-sm text-muted-foreground">
                                        {phoneText(customer.phone)}
                                    </div>
                                )}
                                <CustomerNotes notes={customer.notes} />
                            </div>
                            {!editing && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearCustomer}
                                >
                                    <X /> {t('jobs.form.change_customer')}
                                </Button>
                            )}
                        </div>
                    ) : data.new_customer_mode ? (
                        <div className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                {t('jobs.form.new_customer_hint')}
                            </p>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {textField(
                                    'nc-first_name',
                                    t('customers.fields.first_name'),
                                    data.new_customer.first_name,
                                    (v) => setNewCustomer({ first_name: v }),
                                    errors['new_customer.first_name'],
                                    { autoComplete: 'off' },
                                )}
                                {textField(
                                    'nc-last_name',
                                    t('customers.fields.last_name'),
                                    data.new_customer.last_name,
                                    (v) => setNewCustomer({ last_name: v }),
                                    errors['new_customer.last_name'],
                                    { autoComplete: 'off' },
                                )}
                                {textField(
                                    'nc-phone',
                                    t('customers.fields.phone'),
                                    data.new_customer.phone,
                                    (v) => setNewCustomer({ phone: v }),
                                    errors['new_customer.phone'],
                                    { type: 'tel', autoComplete: 'off' },
                                )}
                                {textField(
                                    'nc-email',
                                    t('customers.fields.email'),
                                    data.new_customer.email,
                                    (v) => setNewCustomer({ email: v }),
                                    errors['new_customer.email'],
                                    { type: 'email', autoComplete: 'off' },
                                )}
                                {textField(
                                    'nc-company_name',
                                    t('customers.fields.company_name'),
                                    data.new_customer.company_name,
                                    (v) => setNewCustomer({ company_name: v }),
                                    errors['new_customer.company_name'],
                                    { autoComplete: 'off' },
                                    'sm:col-span-2',
                                )}
                            </div>

                            <FormField
                                id="nc-notes"
                                label={t('customers.fields.notes')}
                                hint={t('customers.about_hint')}
                                error={errors['new_customer.notes']}
                            >
                                <Textarea
                                    id="nc-notes"
                                    rows={3}
                                    maxLength={10000}
                                    value={data.new_customer.notes}
                                    onChange={(e) =>
                                        setNewCustomer({
                                            notes: e.target.value,
                                        })
                                    }
                                />
                            </FormField>

                            {duplicate && (
                                <Alert>
                                    <AlertTitle>
                                        {t('customers.duplicate_title')}
                                    </AlertTitle>
                                    <AlertDescription className="flex flex-wrap items-center gap-2">
                                        {duplicate.display_name}
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                form.setData(
                                                    'new_customer_mode',
                                                    false,
                                                );
                                                setSearch(
                                                    data.new_customer.phone,
                                                );
                                            }}
                                        >
                                            {t('jobs.form.existing_customer')}
                                        </Button>
                                    </AlertDescription>
                                </Alert>
                            )}

                            <h3 className="text-sm font-medium">
                                {t('customers.sections.property')}
                            </h3>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="nc-line1"
                                    label={t('properties.fields.line1')}
                                    error={
                                        errors['new_customer.property.line1']
                                    }
                                    className="sm:col-span-2"
                                >
                                    <AddressAutocomplete
                                        id="nc-line1"
                                        autoComplete="off"
                                        value={data.new_customer.property.line1}
                                        country={
                                            data.new_customer.property.country
                                        }
                                        onChange={(v) =>
                                            setNewAddress({ line1: v })
                                        }
                                        onPick={(address) =>
                                            setNewAddress(
                                                {
                                                    ...address,
                                                    unit:
                                                        address.unit ??
                                                        data.new_customer
                                                            .property.unit,
                                                },
                                                true,
                                            )
                                        }
                                    />
                                </FormField>
                                {textField(
                                    'nc-unit',
                                    t('properties.fields.unit'),
                                    data.new_customer.property.unit,
                                    (v) => setNewAddress({ unit: v }),
                                    errors['new_customer.property.unit'],
                                )}
                                {textField(
                                    'nc-city',
                                    t('properties.fields.city'),
                                    data.new_customer.property.city,
                                    (v) => setNewAddress({ city: v }),
                                    errors['new_customer.property.city'],
                                )}
                                {textField(
                                    'nc-postal_code',
                                    auth.company?.address.postal_label ??
                                        t('properties.fields.postal_code'),
                                    data.new_customer.property.postal_code,
                                    (v) => setNewAddress({ postal_code: v }),
                                    errors['new_customer.property.postal_code'],
                                )}
                                {textField(
                                    'nc-gate_code',
                                    t('properties.fields.gate_code'),
                                    data.new_customer.property.gate_code,
                                    (v) => setNewAddress({ gate_code: v }),
                                    errors['new_customer.property.gate_code'],
                                )}
                            </div>

                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    form.setData('new_customer_mode', false)
                                }
                            >
                                <Search /> {t('jobs.form.existing_customer')}
                            </Button>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            <FormField
                                id="customer-search"
                                label={t('jobs.form.find_customer')}
                                hint={t('jobs.form.find_customer_hint')}
                                error={errors.customer_id}
                            >
                                <Input
                                    id="customer-search"
                                    type="search"
                                    autoComplete="off"
                                    autoFocus
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </FormField>

                            {searching && (
                                <p className="text-sm text-muted-foreground">
                                    {t('jobs.form.searching')}
                                </p>
                            )}

                            {results && (
                                <ul className="divide-y rounded-md border">
                                    {results.map((c) => (
                                        <li key={c.id}>
                                            <button
                                                type="button"
                                                className="flex min-h-12 w-full flex-col items-start px-3 py-2 text-left hover:bg-muted/50"
                                                onClick={() =>
                                                    chooseCustomer(c)
                                                }
                                            >
                                                <span className="font-medium">
                                                    {c.display_name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {[
                                                        c.phone,
                                                        c.properties[0]
                                                            ?.full_address,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                    {results.length === 0 && (
                                        <li className="p-3 text-sm text-muted-foreground">
                                            {t('jobs.form.no_customers_found')}
                                        </li>
                                    )}
                                </ul>
                            )}

                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    const digits = search.replace(/\D/g, '');
                                    form.setData((d) => ({
                                        ...d,
                                        new_customer_mode: true,
                                        new_customer: {
                                            ...d.new_customer,
                                            phone:
                                                digits.length >= 7
                                                    ? search
                                                    : d.new_customer.phone,
                                            first_name:
                                                digits.length >= 7
                                                    ? d.new_customer.first_name
                                                    : search,
                                        },
                                    }));
                                }}
                            >
                                <UserPlus /> {t('jobs.form.new_customer')}
                            </Button>
                        </div>
                    )}

                    {customer && (
                        <FormField
                            id="property_id"
                            label={t('jobs.fields.property')}
                            error={errors.property_id}
                        >
                            <NativeSelect
                                id="property_id"
                                value={data.property_id ?? ''}
                                onChange={(e) =>
                                    form.setData((d) => ({
                                        ...d,
                                        property_id: Number(e.target.value),
                                        appliance_ids: [],
                                    }))
                                }
                            >
                                {customer.properties.length === 0 && (
                                    <option value="">—</option>
                                )}
                                {customer.properties.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {[p.label, p.full_address]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    )}
                </section>

                {/* Appliances (appliance repair vertical only) */}
                {tracksAppliances && (customer || data.new_customer_mode) && (
                    <section className="space-y-3 rounded-lg border p-4">
                        <h2 className="text-base font-medium">
                            {t('jobs.sections.appliances')}
                        </h2>

                        {property && property.appliances.length > 0 ? (
                            <div className="space-y-2">
                                {property.appliances.map((a) => (
                                    <label
                                        key={a.id}
                                        className="flex min-h-11 items-center gap-3 rounded-md border px-3 py-2"
                                    >
                                        <Checkbox
                                            checked={data.appliance_ids.includes(
                                                a.id,
                                            )}
                                            onCheckedChange={(c) =>
                                                form.setData(
                                                    'appliance_ids',
                                                    toggle(
                                                        data.appliance_ids,
                                                        a.id,
                                                        c === true,
                                                    ),
                                                )
                                            }
                                        />
                                        <span className="text-sm">
                                            <span className="font-medium">
                                                {applianceTitle(a)}
                                            </span>
                                            {a.model_number && (
                                                <span className="text-muted-foreground">
                                                    {' · '}
                                                    {a.model_number}
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        ) : (
                            data.new_appliances.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    {t('jobs.form.no_appliances')}
                                </p>
                            )
                        )}
                        <InputError message={errors.appliance_ids} />

                        {data.new_appliances.map((a, i) => (
                            <div
                                key={i}
                                className="grid gap-3 rounded-md border p-3 sm:grid-cols-2"
                            >
                                <FormField
                                    id={`na-${i}-type`}
                                    label={t('appliances.fields.type')}
                                    error={errors[`new_appliances.${i}.type`]}
                                >
                                    <NativeSelect
                                        id={`na-${i}-type`}
                                        value={a.type}
                                        onChange={(e) =>
                                            setNewAppliance(i, {
                                                type: e.target.value,
                                            })
                                        }
                                    >
                                        {applianceTypes.map((o) => (
                                            <option
                                                key={o.value}
                                                value={o.value}
                                            >
                                                {o.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormField>
                                {textField(
                                    `na-${i}-manufacturer`,
                                    t('appliances.fields.manufacturer'),
                                    a.manufacturer,
                                    (v) =>
                                        setNewAppliance(i, { manufacturer: v }),
                                    errors[`new_appliances.${i}.manufacturer`],
                                    {
                                        list: 'job-manufacturers',
                                        autoComplete: 'off',
                                    },
                                )}
                                {textField(
                                    `na-${i}-model_number`,
                                    t('appliances.fields.model_number'),
                                    a.model_number,
                                    (v) =>
                                        setNewAppliance(i, { model_number: v }),
                                    errors[`new_appliances.${i}.model_number`],
                                    {
                                        autoCapitalize: 'characters',
                                        autoComplete: 'off',
                                    },
                                )}
                                {textField(
                                    `na-${i}-serial_number`,
                                    t('appliances.fields.serial_number'),
                                    a.serial_number,
                                    (v) =>
                                        setNewAppliance(i, {
                                            serial_number: v,
                                        }),
                                    errors[`new_appliances.${i}.serial_number`],
                                    {
                                        autoCapitalize: 'characters',
                                        autoComplete: 'off',
                                    },
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="justify-self-start"
                                    onClick={() =>
                                        form.setData(
                                            'new_appliances',
                                            data.new_appliances.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 /> {t('common.remove')}
                                </Button>
                            </div>
                        ))}
                        <datalist id="job-manufacturers">
                            {manufacturers.map((m) => (
                                <option key={m} value={m} />
                            ))}
                        </datalist>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('new_appliances', [
                                    ...data.new_appliances,
                                    {
                                        type: 'washer',
                                        manufacturer: '',
                                        model_number: '',
                                        serial_number: '',
                                    },
                                ])
                            }
                        >
                            <Plus /> {t('jobs.form.add_new_appliance')}
                        </Button>
                    </section>
                )}

                {/* Job details */}
                <section className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                    <h2 className="text-base font-medium sm:col-span-2">
                        {t('jobs.sections.job')}
                    </h2>
                    <fieldset className="space-y-2 sm:col-span-2">
                        <legend className="mb-2 text-sm font-medium">
                            {t('jobs.fields.visit_type')}
                        </legend>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {visitTypes.map((o) => (
                                <Button
                                    key={o.value}
                                    type="button"
                                    variant={
                                        data.visit_type === o.value
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="h-auto min-h-11 whitespace-normal"
                                    onClick={() =>
                                        form.setData('visit_type', o.value)
                                    }
                                >
                                    {o.label}
                                </Button>
                            ))}
                        </div>
                        <InputError message={errors.visit_type} />
                    </fieldset>
                    {followsUp && (
                        <FormField
                            id="previous_job_id"
                            label={t('jobs.previous_job')}
                            error={errors.previous_job_id}
                            className="sm:col-span-2"
                        >
                            <NativeSelect
                                id="previous_job_id"
                                value={data.previous_job_id ?? ''}
                                onChange={(e) =>
                                    choosePreviousJob(e.target.value)
                                }
                            >
                                <option value="">
                                    {t('jobs.pick_previous_job')}
                                </option>
                                {earlierJobs.map((j) => (
                                    <option key={j.id} value={j.id}>
                                        {[
                                            `#${j.number}`,
                                            j.status_label,
                                            j.appliances,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    )}
                    {warranties && (
                        <div className="space-y-2 rounded-md border p-3 text-sm sm:col-span-2">
                            <p className="font-medium">
                                {t('jobs.callback.warranties', {
                                    number: warranties.number,
                                    date: time.dateOnly(warranties.date),
                                })}
                            </p>
                            {warranties.lines.length === 0 ? (
                                <p className="text-muted-foreground">
                                    {t('jobs.callback.no_lines')}
                                </p>
                            ) : (
                                <ul className="space-y-1">
                                    {warranties.lines.map((line) => (
                                        <li
                                            key={line.item_id}
                                            className="flex justify-between gap-2"
                                        >
                                            <span className="min-w-0 truncate">
                                                {line.description}
                                            </span>
                                            <span
                                                className={
                                                    line.active
                                                        ? 'shrink-0 text-emerald-700 dark:text-emerald-400'
                                                        : 'shrink-0 text-muted-foreground'
                                                }
                                            >
                                                {line.ends_on
                                                    ? t(
                                                          line.active
                                                              ? 'jobs.callback.covered'
                                                              : 'jobs.callback.expired',
                                                          {
                                                              date: time.dateOnly(
                                                                  line.ends_on,
                                                              ),
                                                          },
                                                      )
                                                    : t('jobs.callback.none')}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {t('jobs.callback.free_hint')}
                            </p>
                        </div>
                    )}
                    {data.visit_type === 'return_visit' && (
                        <fieldset className="space-y-2 sm:col-span-2">
                            <legend className="text-sm font-medium">
                                {t('jobs.bring.title')}
                            </legend>
                            <p className="text-xs text-muted-foreground">
                                {t('jobs.bring.hint')}
                            </p>
                            {data.bring_items.map((item, i) => (
                                <div
                                    key={i}
                                    className="grid grid-cols-[1fr_5rem_auto] gap-2"
                                >
                                    <Input
                                        aria-label={t('jobs.bring.item')}
                                        placeholder={t('jobs.bring.item')}
                                        value={item.description}
                                        maxLength={255}
                                        onChange={(e) =>
                                            setBring(i, {
                                                description: e.target.value,
                                            })
                                        }
                                    />
                                    <Input
                                        aria-label={t('jobs.bring.qty')}
                                        inputMode="decimal"
                                        value={item.quantity}
                                        onChange={(e) =>
                                            setBring(i, {
                                                quantity: e.target.value,
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-10"
                                        aria-label={t('jobs.bring.remove')}
                                        onClick={() =>
                                            form.setData(
                                                'bring_items',
                                                data.bring_items.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                    <InputError
                                        className="col-span-3"
                                        message={
                                            errors[
                                                `bring_items.${i}.description`
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('bring_items', [
                                        ...data.bring_items,
                                        { description: '', quantity: '1' },
                                    ])
                                }
                            >
                                <Plus /> {t('jobs.bring.add')}
                            </Button>
                        </fieldset>
                    )}
                    {brands.length > 1 || errors.brand_id ? (
                        <FormField
                            id="brand_id"
                            label={t('jobs.fields.brand')}
                            error={errors.brand_id}
                        >
                            <NativeSelect
                                id="brand_id"
                                value={data.brand_id}
                                onChange={(e) =>
                                    form.setData('brand_id', e.target.value)
                                }
                            >
                                {brands.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    ) : null}
                    <FormField
                        id="job_type"
                        label={t('jobs.fields.job_type')}
                        error={errors.job_type}
                    >
                        <NativeSelect
                            id="job_type"
                            value={data.job_type}
                            onChange={(e) =>
                                form.setData('job_type', e.target.value)
                            }
                        >
                            {jobTypes.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="lead_source"
                        label={t('jobs.fields.lead_source')}
                        error={errors.lead_source}
                    >
                        <NativeSelect
                            id="lead_source"
                            value={data.lead_source}
                            onChange={(e) =>
                                form.setData('lead_source', e.target.value)
                            }
                        >
                            <option value="">—</option>
                            {leadSources.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="description"
                        label={t('jobs.fields.description')}
                        hint={t('jobs.fields.description_hint')}
                        error={errors.description}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            id="description"
                            rows={3}
                            value={data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="notes"
                        label={t('jobs.fields.notes')}
                        error={errors.notes}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            id="notes"
                            rows={2}
                            value={data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </FormField>
                </section>

                {/* First visit */}
                {!editing && (
                    <section className="space-y-4 rounded-lg border p-4">
                        <label className="flex items-center gap-3 text-base font-medium">
                            <Checkbox
                                checked={data.add_visit}
                                onCheckedChange={(c) =>
                                    form.setData('add_visit', c === true)
                                }
                            />
                            {t('jobs.form.add_visit')}
                        </label>

                        {data.add_visit && (
                            <VisitFields
                                value={data.visit}
                                onChange={setVisit}
                                errors={errors}
                                errorPrefix="visit."
                                assignableUsers={assignableUsers}
                            />
                        )}
                    </section>
                )}

                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button type="submit" size="lg" disabled={form.processing}>
                        {t('common.save')}
                    </Button>
                    <Button type="button" variant="ghost" size="lg" asChild>
                        <Link href={job ? show(job.id) : index()}>
                            {t('common.cancel')}
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

JobForm.layout = {
    breadcrumbs: [{ title: 'jobs.title', href: index() }],
};
