import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    CalendarPlus,
    Search,
    UserPlus,
    UserRound,
    Wrench,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import {
    AddressAutocomplete,
    clearedPlace,
} from '@/components/customers/address-autocomplete';
import { ApplianceTypePicker } from '@/components/appliance-image';
import { CustomerNotes } from '@/components/customers/customer-notes';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import type { Assignable } from '@/components/jobs/types';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as brandsPage } from '@/routes/brands';
import { create, index, lookup, store } from '@/routes/jobs';
import type { Option } from '@/types';

type CustomerChoice = {
    id: number;
    display_name: string;
    phone: string | null;
    notes: string | null;
    properties: {
        id: number;
        full_address: string;
        is_primary: boolean;
        appliances?: { id: number; type: string }[];
    }[];
};

export default function QuickBook({
    booking,
    openInvoice,
    bookingDate,
    today,
    brands,
    jobTypes,
    applianceTypes,
    assignableUsers,
    customer: initialCustomer,
}: {
    booking: boolean;
    openInvoice: boolean;
    bookingDate: string | null;
    today: string;
    brands: Option[];
    jobTypes: Option[];
    applianceTypes: Option[];
    assignableUsers: Assignable[];
    customer: CustomerChoice | null;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const [existing, setExisting] = useState(initialCustomer !== null);
    const [customer, setCustomer] = useState(initialCustomer);
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<CustomerChoice[]>([]);
    const [searchError, setSearchError] = useState(false);
    const [searching, setSearching] = useState(false);
    // Optional: the tech can add the appliance on site from the rating plate.
    const [applianceType, setApplianceType] = useState('');
    const tracksAppliances = auth.company?.tracks_appliances ?? true;
    const form = useForm({
        quick_booking: true,
        open_invoice: openInvoice,
        brand_id: brands[0]?.value ?? '',
        job_type:
            jobTypes.find((o) => o.value === 'repair')?.value ??
            jobTypes[0]?.value ??
            '',
        new_customer_mode: initialCustomer === null,
        customer_id: initialCustomer?.id ?? (null as number | null),
        property_id:
            (
                initialCustomer?.properties.find((p) => p.is_primary) ??
                initialCustomer?.properties[0]
            )?.id ?? (null as number | null),
        new_customer: {
            first_name: '',
            phone: '',
            notes: '',
            property: {
                line1: '',
                unit: '',
                city: '',
                region: '',
                postal_code: '',
                country: auth.company?.country ?? '',
                ...clearedPlace,
            },
        },
        description: '',
        add_visit: booking,
        visit: {
            date: bookingDate ?? today,
            start_time: '09:00',
            end_time: '11:00',
            estimated_duration_minutes: 60,
            assignee_ids:
                assignableUsers.length === 1
                    ? [assignableUsers[0].id]
                    : ([] as number[]),
        },
    });

    useEffect(() => {
        if (!existing || search.trim().length < 2) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    lookup({ query: { search } }).url,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!response.ok) throw new Error('Customer search failed');
                const data: { customers: CustomerChoice[] } =
                    await response.json();
                setResults(data.customers);
                setSearchError(false);
            } catch {
                if (!controller.signal.aborted) setSearchError(true);
            } finally {
                if (!controller.signal.aborted) setSearching(false);
            }
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [search, existing]);

    const choose = (choice: CustomerChoice) => {
        setCustomer(choice);
        setSearch('');
        setResults([]);
        const property =
            choice.properties.find((p) => p.is_primary) ?? choice.properties[0];
        form.setData((data) => ({
            ...data,
            new_customer_mode: false,
            customer_id: choice.id,
            property_id: property?.id ?? null,
        }));
    };
    const switchMode = () => {
        setExisting(!existing);
        setCustomer(null);
        setSearch('');
        setResults([]);
        setSearchError(false);
        setSearching(false);
        form.clearErrors();
        form.setData((data) => ({
            ...data,
            new_customer_mode: existing,
            customer_id: null,
            property_id: null,
        }));
    };
    const canSave =
        !!form.data.brand_id &&
        (existing
            ? !!form.data.customer_id && !!form.data.property_id
            : !!form.data.new_customer.first_name.trim() &&
              !!form.data.new_customer.phone.trim()) &&
        (!booking ||
            (!!form.data.visit.date &&
                !!form.data.visit.start_time &&
                form.data.visit.end_time > form.data.visit.start_time));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!canSave || form.processing) return;
        // An appliance of that type already at the address is linked instead of added twice.
        const known = customer?.properties
            .find((p) => p.id === form.data.property_id)
            ?.appliances?.find((a) => a.type === applianceType);
        form.transform((data) => ({
            ...data,
            ...(applianceType === ''
                ? {}
                : known
                  ? { appliance_ids: [known.id] }
                  : { new_appliances: [{ type: applianceType }] }),
        }));
        form.post(store().url);
    };
    const title = t(booking ? 'nav.book_customer' : 'invoices.add');
    const errors = form.errors as Record<string, string | undefined>;
    const locale = auth.company?.locale;
    const setProperty = (
        changes: Partial<typeof form.data.new_customer.property>,
    ) =>
        form.setData('new_customer', {
            ...form.data.new_customer,
            property: { ...form.data.new_customer.property, ...changes },
        });
    // One tap for the day and one for the arrival window; exact times stay editable below.
    const dayChoices = [0, 1, 2, 3].map((offset) => {
        const date = new Date(`${today}T00:00:00Z`);
        date.setUTCDate(date.getUTCDate() + offset);
        const ymd = date.toISOString().slice(0, 10);
        return {
            ymd,
            label:
                offset === 0
                    ? t('jobs.quick.today')
                    : offset === 1
                      ? t('jobs.quick.tomorrow')
                      : new Intl.DateTimeFormat(locale, {
                            weekday: 'short',
                            timeZone: 'UTC',
                        }).format(date),
            day: new Intl.DateTimeFormat(locale, {
                month: 'short',
                day: 'numeric',
                timeZone: 'UTC',
            }).format(date),
        };
    });
    // Short range labels in the company's own time format, e.g. "9 – 11 a.m.".
    const hourRange = new Intl.DateTimeFormat(locale, {
        hour: 'numeric',
        timeZone: 'UTC',
    });
    const windows = [9, 11, 13, 15].map((from) => ({
        start: `${String(from).padStart(2, '0')}:00`,
        end: `${String(from + 2).padStart(2, '0')}:00`,
        label: hourRange.formatRange(
            new Date(Date.UTC(1970, 0, 1, from)),
            new Date(Date.UTC(1970, 0, 1, from + 2)),
        ),
    }));
    const setVisit = (changes: Partial<typeof form.data.visit>) =>
        form.setData('visit', { ...form.data.visit, ...changes });
    const assigneeError = Object.entries(errors).find(([key]) =>
        key.startsWith('visit.assignee_ids'),
    )?.[1];

    return (
        <>
            <Head title={title} />
            <div className="mx-auto w-full max-w-2xl p-4 sm:p-6">
                <PageHeader
                    title={title}
                    description={t(
                        booking ? 'jobs.quick.hint' : 'jobs.quick.invoice_hint',
                    )}
                />
                {brands.length === 0 && (
                    <div className="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        <p>
                            {t(
                                auth.can.manageCompany
                                    ? 'dashboard.brand_needed_hint'
                                    : 'dashboard.brand_needed_admin',
                            )}
                        </p>
                        {auth.can.manageCompany && (
                            <Button asChild variant="outline" className="mt-3">
                                <Link href={brandsPage()}>
                                    {t('dashboard.brand_needed')}
                                </Link>
                            </Button>
                        )}
                    </div>
                )}
                <form onSubmit={submit} className="space-y-5">
                    <section className="da-card space-y-4 p-4 sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <UserRound className="size-5 text-primary" />
                                {t('jobs.fields.customer')}
                            </h2>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={switchMode}
                            >
                                {existing ? <UserPlus /> : <Search />}
                                {t(
                                    existing
                                        ? 'jobs.quick.new_customer'
                                        : 'jobs.quick.existing_customer',
                                )}
                            </Button>
                        </div>
                        {existing ? (
                            <>
                                <FormField
                                    id="booking-search"
                                    label={t('jobs.quick.find_customer')}
                                >
                                    <Input
                                        id="booking-search"
                                        type="search"
                                        value={search}
                                        placeholder={t(
                                            'jobs.quick.search_hint',
                                        )}
                                        onChange={(event) => {
                                            const value = event.target.value;
                                            setSearch(value);
                                            setResults([]);
                                            setSearchError(false);
                                            setSearching(
                                                value.trim().length >= 2,
                                            );
                                        }}
                                    />
                                </FormField>
                                {search.trim().length >= 2 && (
                                    <div className="space-y-2">
                                        {results.map((choice) => (
                                            <Button
                                                key={choice.id}
                                                type="button"
                                                variant="outline"
                                                className="h-auto min-h-11 w-full justify-start text-left whitespace-normal"
                                                onClick={() => choose(choice)}
                                            >
                                                {choice.display_name}
                                                {choice.phone &&
                                                    ` · ${choice.phone}`}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                                {searchError && (
                                    <InputError
                                        message={t('jobs.quick.search_failed')}
                                    />
                                )}
                                {searching && (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t('jobs.quick.searching')}
                                    </p>
                                )}
                                {search.trim().length >= 2 &&
                                    !searching &&
                                    !searchError &&
                                    results.length === 0 && (
                                        <p
                                            role="status"
                                            className="text-sm text-muted-foreground"
                                        >
                                            {t('jobs.quick.search_empty')}
                                        </p>
                                    )}
                                {customer && (
                                    <div className="space-y-2 rounded-xl bg-muted p-3">
                                        <p className="font-semibold">
                                            {customer.display_name}
                                        </p>
                                        <CustomerNotes notes={customer.notes} />
                                        {customer.properties.length > 0 ? (
                                            <FormField
                                                id="booking-property"
                                                label={t(
                                                    'jobs.fields.property',
                                                )}
                                                error={errors.property_id}
                                            >
                                                <NativeSelect
                                                    id="booking-property"
                                                    value={
                                                        form.data.property_id ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'property_id',
                                                            Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    {customer.properties.map(
                                                        (property) => (
                                                            <option
                                                                key={
                                                                    property.id
                                                                }
                                                                value={
                                                                    property.id
                                                                }
                                                            >
                                                                {property.full_address ||
                                                                    t(
                                                                        'jobs.quick.address_pending',
                                                                    )}
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                            </FormField>
                                        ) : (
                                            <p className="text-sm text-destructive">
                                                {t(
                                                    'jobs.quick.property_needed',
                                                )}
                                            </p>
                                        )}
                                    </div>
                                )}
                                <InputError message={errors.customer_id} />
                            </>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="booking-phone"
                                    label={t('customers.fields.phone')}
                                    error={errors['new_customer.phone']}
                                >
                                    <Input
                                        id="booking-phone"
                                        type="tel"
                                        inputMode="tel"
                                        autoComplete="tel"
                                        required
                                        maxLength={32}
                                        value={form.data.new_customer.phone}
                                        onChange={(event) =>
                                            form.setData('new_customer', {
                                                ...form.data.new_customer,
                                                phone: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-name"
                                    label={t('jobs.quick.name')}
                                    error={errors['new_customer.first_name']}
                                >
                                    <Input
                                        id="booking-name"
                                        autoComplete="name"
                                        required
                                        maxLength={100}
                                        value={
                                            form.data.new_customer.first_name
                                        }
                                        onChange={(event) =>
                                            form.setData('new_customer', {
                                                ...form.data.new_customer,
                                                first_name: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-address"
                                    label={t('jobs.quick.address')}
                                    className="sm:col-span-2"
                                    error={
                                        errors['new_customer.property.line1']
                                    }
                                >
                                    <AddressAutocomplete
                                        id="booking-address"
                                        autoComplete="street-address"
                                        value={
                                            form.data.new_customer.property
                                                .line1
                                        }
                                        country={
                                            form.data.new_customer.property
                                                .country ||
                                            auth.company?.country ||
                                            'US'
                                        }
                                        onChange={(value) =>
                                            setProperty({
                                                line1: value,
                                                ...clearedPlace,
                                            })
                                        }
                                        onPick={(address) =>
                                            setProperty({
                                                ...address,
                                                unit:
                                                    address.unit ??
                                                    form.data.new_customer
                                                        .property.unit,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-unit"
                                    label={t('jobs.quick.unit')}
                                    error={errors['new_customer.property.unit']}
                                >
                                    <Input
                                        id="booking-unit"
                                        autoComplete="address-line2"
                                        maxLength={50}
                                        value={
                                            form.data.new_customer.property.unit
                                        }
                                        onChange={(event) =>
                                            setProperty({
                                                unit: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-city"
                                    label={t('properties.fields.city')}
                                    error={errors['new_customer.property.city']}
                                >
                                    <Input
                                        id="booking-city"
                                        autoComplete="address-level2"
                                        maxLength={100}
                                        value={
                                            form.data.new_customer.property.city
                                        }
                                        onChange={(event) =>
                                            setProperty({
                                                city: event.target.value,
                                                ...clearedPlace,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-notes"
                                    label={t('jobs.quick.customer_notes')}
                                    className="sm:col-span-2"
                                    error={errors['new_customer.notes']}
                                >
                                    <Input
                                        id="booking-notes"
                                        value={form.data.new_customer.notes}
                                        onChange={(event) =>
                                            form.setData('new_customer', {
                                                ...form.data.new_customer,
                                                notes: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            </div>
                        )}
                        {brands.length > 1 && (
                            <FormField
                                id="booking-brand"
                                label={t('jobs.fields.brand')}
                                error={errors.brand_id}
                            >
                                <NativeSelect
                                    id="booking-brand"
                                    value={form.data.brand_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'brand_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {brands.map((brand) => (
                                        <option
                                            key={brand.value}
                                            value={brand.value}
                                        >
                                            {brand.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                        )}
                        {brands.length <= 1 && (
                            <InputError message={errors.brand_id} />
                        )}
                    </section>
                    <section className="da-card space-y-3 p-4 sm:p-5">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <Wrench className="size-5 text-primary" />
                            {t('jobs.quick.problem_title')}
                        </h2>
                        {tracksAppliances && (
                            <div className="space-y-2">
                                <span className="text-[13px] font-semibold">
                                    {t('jobs.quick.appliance')}
                                </span>
                                <ApplianceTypePicker
                                    label={t('jobs.quick.appliance')}
                                    options={applianceTypes}
                                    value={applianceType}
                                    onChange={(type) =>
                                        setApplianceType(
                                            type === applianceType ? '' : type,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('jobs.quick.appliance_hint')}
                                </p>
                                <InputError
                                    message={
                                        errors['new_appliances.0.type'] ??
                                        errors.appliance_ids
                                    }
                                />
                            </div>
                        )}
                        <FormField
                            id="booking-problem"
                            label={t('jobs.quick.problem')}
                            error={errors.description}
                        >
                            <Textarea
                                id="booking-problem"
                                rows={2}
                                placeholder={t('jobs.quick.problem_hint')}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </section>
                    {booking && (
                        <section className="da-card space-y-4 p-4 sm:p-5">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <CalendarPlus className="size-5 text-primary" />
                                {t('jobs.quick.appointment')}
                            </h2>
                            <div className="grid grid-cols-4 gap-1.5">
                                {dayChoices.map((choice) => (
                                    <button
                                        key={choice.ymd}
                                        type="button"
                                        aria-pressed={
                                            form.data.visit.date === choice.ymd
                                        }
                                        onClick={() =>
                                            setVisit({ date: choice.ymd })
                                        }
                                        className="da-chip da-press flex min-h-14 flex-col items-center justify-center rounded-2xl px-1 leading-tight"
                                    >
                                        <span className="text-xs font-semibold opacity-85">
                                            {choice.label}
                                        </span>
                                        <span className="text-sm font-bold">
                                            {choice.day}
                                        </span>
                                    </button>
                                ))}
                            </div>
                            <div>
                                <p className="mb-1.5 text-sm font-medium">
                                    {t('jobs.quick.arrival_window')}
                                </p>
                                <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                                    {windows.map((w) => (
                                        <button
                                            key={w.start}
                                            type="button"
                                            aria-pressed={
                                                form.data.visit.start_time ===
                                                    w.start &&
                                                form.data.visit.end_time ===
                                                    w.end
                                            }
                                            onClick={() =>
                                                setVisit({
                                                    start_time: w.start,
                                                    end_time: w.end,
                                                })
                                            }
                                            className="da-chip da-press min-h-12 rounded-2xl px-1 text-sm font-semibold"
                                        >
                                            {w.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <FormField
                                    id="booking-date"
                                    label={t('jobs.visit_fields.date')}
                                    className="col-span-2"
                                    error={errors['visit.date']}
                                >
                                    <Input
                                        id="booking-date"
                                        type="date"
                                        required
                                        value={form.data.visit.date}
                                        onChange={(event) =>
                                            form.setData('visit', {
                                                ...form.data.visit,
                                                date: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-from"
                                    label={t('jobs.visit_fields.start_time')}
                                    error={errors['visit.start_time']}
                                >
                                    <Input
                                        id="booking-from"
                                        type="time"
                                        required
                                        value={form.data.visit.start_time}
                                        onChange={(event) =>
                                            form.setData('visit', {
                                                ...form.data.visit,
                                                start_time: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-to"
                                    label={t('jobs.visit_fields.end_time')}
                                    error={errors['visit.end_time']}
                                >
                                    <Input
                                        id="booking-to"
                                        type="time"
                                        required
                                        value={form.data.visit.end_time}
                                        onChange={(event) =>
                                            form.setData('visit', {
                                                ...form.data.visit,
                                                end_time: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            </div>
                            {assignableUsers.length > 1 && (
                                <div>
                                    <p className="mb-1.5 text-sm font-medium">
                                        {t('jobs.quick.technician')}
                                    </p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {[
                                            ...assignableUsers.map((user) => ({
                                                id: user.id as number | null,
                                                name: user.name,
                                            })),
                                            {
                                                id: null,
                                                name: t('calendar.unassigned'),
                                            },
                                        ].map((choice) => (
                                            <button
                                                key={choice.id ?? 'none'}
                                                type="button"
                                                aria-pressed={
                                                    (form.data.visit
                                                        .assignee_ids[0] ??
                                                        null) === choice.id
                                                }
                                                onClick={() =>
                                                    setVisit({
                                                        assignee_ids:
                                                            choice.id === null
                                                                ? []
                                                                : [choice.id],
                                                    })
                                                }
                                                className={cn(
                                                    'da-chip da-press min-h-12 rounded-2xl px-4 text-sm font-semibold',
                                                )}
                                            >
                                                {choice.name}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                            <InputError message={assigneeError} />
                        </section>
                    )}
                    {Object.entries(errors)
                        .filter(
                            ([key]) =>
                                ![
                                    'brand_id',
                                    'customer_id',
                                    'property_id',
                                    'description',
                                    'new_customer.first_name',
                                    'new_customer.phone',
                                    'new_customer.notes',
                                    'new_customer.property.line1',
                                    'new_customer.property.city',
                                    'new_customer.property.unit',
                                    'visit.date',
                                    'visit.start_time',
                                    'visit.end_time',
                                ].includes(key) &&
                                !key.startsWith('visit.assignee_ids'),
                        )
                        .map(([key, message]) => (
                            <InputError key={key} message={message} />
                        ))}
                    <div className="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            size="lg"
                            className="min-h-14 flex-1 text-base"
                            disabled={!canSave || form.processing}
                        >
                            {t(
                                booking
                                    ? 'jobs.quick.book'
                                    : 'jobs.quick.continue_invoice',
                            )}
                        </Button>
                        <Button asChild variant="outline" size="lg">
                            <Link href={index()}>{t('common.cancel')}</Link>
                        </Button>
                    </div>
                    <Link
                        href={create()}
                        className="inline-block text-sm text-muted-foreground underline underline-offset-4"
                    >
                        {t('jobs.quick.full_form')}
                    </Link>
                </form>
            </div>
        </>
    );
}
