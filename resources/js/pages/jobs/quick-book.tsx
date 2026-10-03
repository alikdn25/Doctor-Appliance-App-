import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CalendarPlus, Search, UserPlus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
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
import { index as brandsPage } from '@/routes/brands';
import { create, index, lookup, store } from '@/routes/jobs';
import type { Option } from '@/types';

type CustomerChoice = {
    id: number;
    display_name: string;
    phone: string | null;
    notes: string | null;
    properties: { id: number; full_address: string; is_primary: boolean }[];
};

export default function QuickBook({
    booking,
    openInvoice,
    bookingDate,
    today,
    brands,
    jobTypes,
    assignableUsers,
    customer: initialCustomer,
}: {
    booking: boolean;
    openInvoice: boolean;
    bookingDate: string | null;
    today: string;
    brands: Option[];
    jobTypes: Option[];
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
                city: '',
                country: auth.company?.country ?? '',
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
        form.post(store().url);
    };
    const title = t(booking ? 'nav.book_customer' : 'invoices.add');
    const errors = form.errors as Record<string, string | undefined>;

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
                    <section className="space-y-4 rounded-2xl border bg-card p-4 sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">
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
                                    id="booking-phone"
                                    label={t('customers.fields.phone')}
                                    error={errors['new_customer.phone']}
                                >
                                    <Input
                                        id="booking-phone"
                                        type="tel"
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
                    {booking && (
                        <section className="space-y-4 rounded-2xl border bg-card p-4 sm:p-5">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <CalendarPlus className="size-5 text-primary" />
                                {t('jobs.quick.appointment')}
                            </h2>
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
                                <FormField
                                    id="booking-assignee"
                                    label={t('jobs.quick.technician')}
                                    error={
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith(
                                                'visit.assignee_ids',
                                            ),
                                        )?.[1]
                                    }
                                >
                                    <NativeSelect
                                        id="booking-assignee"
                                        value={
                                            form.data.visit.assignee_ids[0] ??
                                            ''
                                        }
                                        onChange={(event) =>
                                            form.setData('visit', {
                                                ...form.data.visit,
                                                assignee_ids: event.target.value
                                                    ? [
                                                          Number(
                                                              event.target
                                                                  .value,
                                                          ),
                                                      ]
                                                    : [],
                                            })
                                        }
                                    >
                                        <option value="">
                                            {t('calendar.unassigned')}
                                        </option>
                                        {assignableUsers.map((user) => (
                                            <option
                                                key={user.id}
                                                value={user.id}
                                            >
                                                {user.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormField>
                            )}
                            {assignableUsers.length <= 1 && (
                                <InputError
                                    message={
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith(
                                                'visit.assignee_ids',
                                            ),
                                        )?.[1]
                                    }
                                />
                            )}
                        </section>
                    )}
                    <details
                        open={
                            !!errors['new_customer.property.line1'] ||
                            !!errors['new_customer.property.city'] ||
                            !!errors['new_customer.notes'] ||
                            !!errors.description
                        }
                        className="rounded-2xl border bg-card p-4 sm:p-5"
                    >
                        <summary className="cursor-pointer font-medium">
                            {t('jobs.quick.more')}
                        </summary>
                        <div className="mt-4 space-y-4">
                            {!existing && (
                                <>
                                    <FormField
                                        id="booking-address"
                                        label={t('properties.fields.line1')}
                                        error={
                                            errors[
                                                'new_customer.property.line1'
                                            ]
                                        }
                                    >
                                        <Input
                                            id="booking-address"
                                            autoComplete="street-address"
                                            value={
                                                form.data.new_customer.property
                                                    .line1
                                            }
                                            onChange={(event) =>
                                                form.setData('new_customer', {
                                                    ...form.data.new_customer,
                                                    property: {
                                                        ...form.data
                                                            .new_customer
                                                            .property,
                                                        line1: event.target
                                                            .value,
                                                    },
                                                })
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="booking-city"
                                        label={t('properties.fields.city')}
                                        error={
                                            errors['new_customer.property.city']
                                        }
                                    >
                                        <Input
                                            id="booking-city"
                                            autoComplete="address-level2"
                                            value={
                                                form.data.new_customer.property
                                                    .city
                                            }
                                            onChange={(event) =>
                                                form.setData('new_customer', {
                                                    ...form.data.new_customer,
                                                    property: {
                                                        ...form.data
                                                            .new_customer
                                                            .property,
                                                        city: event.target
                                                            .value,
                                                    },
                                                })
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="booking-notes"
                                        label={t('jobs.quick.customer_notes')}
                                        error={errors['new_customer.notes']}
                                    >
                                        <Textarea
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
                                </>
                            )}
                            <FormField
                                id="booking-problem"
                                label={t('jobs.fields.description')}
                                error={errors.description}
                            >
                                <Textarea
                                    id="booking-problem"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        </div>
                    </details>
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
                            className="min-h-11 flex-1"
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
