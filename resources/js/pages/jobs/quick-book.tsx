import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronDown,
    ExternalLink,
    MapPin,
    Search,
    X,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ApplianceTypePicker } from '@/components/appliance-image';
import {
    AddressAutocomplete,
    clearedPlace,
} from '@/components/customers/address-autocomplete';
import { CustomerNotes } from '@/components/customers/customer-notes';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import type { Assignable } from '@/components/jobs/types';
import { ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import { Button } from '@/components/ui/button';
import { DateChips } from '@/components/ui/date-chips';
import { IconButton } from '@/components/ui/icon-button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { PhoneInput } from '@/components/ui/phone-input';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { Textarea } from '@/components/ui/textarea';
import { TimeWindowChips } from '@/components/ui/time-window-chips';
import { useTrans } from '@/lib/i18n';
import { isPossiblePhone, usePhone } from '@/lib/phone';
import { cn } from '@/lib/utils';
import { index as brandsPage } from '@/routes/brands';
import { create, index, lookup, store } from '@/routes/jobs';
import type { Option } from '@/types';

type CustomerChoice = {
    id: number;
    display_name: string;
    phone: string | null;
    notes: string | null;
    jobs_count?: number;
    properties: {
        id: number;
        full_address: string;
        is_primary: boolean;
        appliances?: { id: number; type: string }[];
    }[];
};

type Window = { start: string; end: string };
type Busy = { date: string; start: string; end: string };
/** The chosen time: "Now", one of the company's windows, or a custom time typed below. */
type Slot =
    | { kind: 'now' }
    | ({ kind: 'window' } & Window)
    | { kind: 'custom' };

/** Where the full job form picks up what was typed here ("Open full job form"). */
export const JOB_DRAFT_KEY = 'job-draft';

const toMinutes = (hhmm: string) =>
    Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5));
const toHhmm = (minutes: number) => {
    const m = Math.max(0, Math.min(minutes, 23 * 60 + 59));

    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
};
const addDays = (ymd: string, days: number) => {
    const date = new Date(`${ymd}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
};

/** Customers found by phone or name, with [Use]. */
function Matches({
    matches,
    onUse,
}: {
    matches: CustomerChoice[];
    onUse: (choice: CustomerChoice) => void;
}) {
    const t = useTrans();

    if (matches.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 space-y-2" data-test="customer-matches">
            {matches.map((match) => (
                <li
                    key={match.id}
                    className="da-card flex items-center gap-3 p-3 text-sm"
                >
                    <span className="min-w-0 flex-1">
                        <span className="block truncate font-semibold">
                            {match.display_name}
                        </span>
                        <span className="block truncate text-muted-foreground">
                            {[
                                match.properties[0]?.full_address,
                                match.jobs_count !== undefined &&
                                    t('jobs.quick.jobs_count', {
                                        count: match.jobs_count,
                                    }),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => onUse(match)}
                    >
                        {t('jobs.quick.use')}
                    </Button>
                </li>
            ))}
        </ul>
    );
}

/** A titled step of the form. */
function Step({
    title,
    hint,
    children,
    className,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('space-y-2', className)}>
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold">{title}</h2>
                {hint && (
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                )}
            </div>
            {children}
        </section>
    );
}

/**
 * Book customer (UX-REDESIGN §7.1, §25) on one page, in the order of a phone call: Phone (a caller already in the
 * book is offered after 3 digits), Name (the magnifier searches by name), Appliance, Problem, When (day chips and
 * the company's arrival windows; "Now" first for today), Technician (free / busy in that window), Address
 * (optional) and More details. Book is pinned at the bottom with what is still missing; ✕ cancels and "⋯" opens
 * the full job form with what was typed.
 */
export default function QuickBook({
    booking,
    openInvoice,
    bookingDate,
    bookingStart = null,
    bookingTechnician = null,
    today,
    nowTime = '00:00',
    brands,
    jobTypes,
    applianceTypes,
    leadSources = [],
    arrivalWindows = [],
    bookingTechnicians = [],
    busy = {},
    customer: initialCustomer,
}: {
    booking: boolean;
    openInvoice: boolean;
    bookingDate: string | null;
    bookingStart?: string | null;
    bookingTechnician?: number | null;
    today: string;
    nowTime?: string;
    brands: Option[];
    jobTypes: Option[];
    applianceTypes: Option[];
    leadSources?: Option[];
    assignableUsers: Assignable[];
    arrivalWindows?: Window[];
    bookingTechnicians?: { id: number; name: string }[];
    busy?: Record<number, Busy[]>;
    customer: CustomerChoice | null;
}) {
    const t = useTrans();
    const phoneText = usePhone();
    const { auth } = usePage().props;
    const country = auth.company?.country ?? 'US';
    const locale = auth.company?.locale ?? 'en-US';
    const tracksAppliances = auth.company?.tracks_appliances ?? true;
    const [customer, setCustomer] = useState(initialCustomer);
    const [applianceType, setApplianceType] = useState('');
    const [nameSearch, setNameSearch] = useState(false);
    const [matches, setMatches] = useState<CustomerChoice[]>([]);
    const [addressOpen, setAddressOpen] = useState(false);
    const [phoneTouched, setPhoneTouched] = useState(false);

    // The first window that has not started yet today; after the last one, tomorrow's first.
    const upcomingToday = arrivalWindows.filter((w) => w.start > nowTime);
    const firstDate =
        bookingDate ?? (upcomingToday.length > 0 ? today : addDays(today, 1));
    const firstWindow =
        firstDate === today ? upcomingToday[0] : arrivalWindows[0];
    // A tap on an empty calendar slot brings its time: the window starting then, or a two-hour custom time.
    const slotWindow = bookingStart
        ? arrivalWindows.find((w) => w.start === bookingStart)
        : undefined;
    const [slot, setSlot] = useState<Slot>(
        bookingStart
            ? slotWindow
                ? { kind: 'window', ...slotWindow }
                : { kind: 'custom' }
            : firstWindow
              ? { kind: 'window', ...firstWindow }
              : { kind: 'custom' },
    );

    const form = useForm({
        quick_booking: true,
        open_invoice: openInvoice,
        brand_id: brands[0]?.value ?? '',
        job_type:
            jobTypes.find((o) => o.value === 'repair')?.value ??
            jobTypes[0]?.value ??
            '',
        lead_source: '',
        notes: '',
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
                country,
                ...clearedPlace,
            },
        },
        description: '',
        add_visit: booking,
        visit: {
            date: firstDate,
            start_time: bookingStart ?? firstWindow?.start ?? '09:00',
            end_time: bookingStart
                ? toHhmm(toMinutes(bookingStart) + 120)
                : (firstWindow?.end ?? '11:00'),
            estimated_duration_minutes: 60,
            // One person on calls (a one-person business): booked to them. Otherwise Unassigned until picked.
            assignee_ids: bookingTechnician
                ? [bookingTechnician]
                : bookingTechnicians.length === 1
                  ? [bookingTechnicians[0].id]
                  : ([] as number[]),
        },
    });
    const { data } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const existing = customer !== null;
    const isToday = data.visit.date === today;
    const technicianId = data.visit.assignee_ids[0] ?? null;

    // "Now" (§25): from this minute until the technician's next job today, or one hour.
    const nowRange = (userId: number | null): Window => {
        const start = Math.floor(toMinutes(nowTime) / 5) * 5;
        const next = (userId === null ? [] : (busy[userId] ?? []))
            .filter((b) => b.date === today && toMinutes(b.start) > start)
            .map((b) => toMinutes(b.start))
            .sort((a, b) => a - b)[0];
        const end =
            next !== undefined && next - start >= 10 ? next : start + 60;

        return { start: toHhmm(start), end: toHhmm(end) };
    };
    const times: Window =
        slot.kind === 'now'
            ? nowRange(technicianId)
            : slot.kind === 'window'
              ? { start: slot.start, end: slot.end }
              : { start: data.visit.start_time, end: data.visit.end_time };

    // Live search: the phone from 3 digits, the name (magnifier) from 2 letters.
    // A search still running when Book is pressed is cancelled: a request after the booking would use up the
    // "Booked …" message before the next screen shows it.
    const lookupAbort = useRef<AbortController | null>(null);
    const phoneDigits = data.new_customer.phone.replace(/\D/g, '');
    const nameTerm = data.new_customer.first_name.trim();
    useEffect(() => {
        setMatches([]);
        if (existing) return;
        const byName = nameSearch && nameTerm.length >= 2;
        if (!byName && phoneDigits.length < 3) return;
        const controller = new AbortController();
        lookupAbort.current = controller;
        const timer = setTimeout(() => {
            if (controller.signal.aborted) return;
            fetch(
                lookup({
                    query: byName
                        ? { search: nameTerm }
                        : { search: phoneDigits, by: 'phone' },
                }).url,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => (response.ok ? response.json() : null))
                .then((found: { customers: CustomerChoice[] } | null) =>
                    setMatches(found?.customers ?? []),
                )
                .catch(() => undefined);
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [phoneDigits, nameTerm, nameSearch, existing]);

    const useCustomer = (choice: CustomerChoice) => {
        setCustomer(choice);
        setMatches([]);
        setNameSearch(false);
        form.clearErrors();
        const property =
            choice.properties.find((p) => p.is_primary) ?? choice.properties[0];
        form.setData((d) => ({
            ...d,
            new_customer_mode: false,
            customer_id: choice.id,
            property_id: property?.id ?? null,
        }));
    };
    const changeCustomer = () => {
        setCustomer(null);
        form.setData((d) => ({
            ...d,
            new_customer_mode: true,
            customer_id: null,
            property_id: null,
        }));
    };

    const phoneValid =
        existing || isPossiblePhone(data.new_customer.phone, country);
    const phoneError =
        errors['new_customer.phone'] ??
        (phoneTouched && data.new_customer.phone.trim() && !phoneValid
            ? t('jobs.quick.phone_invalid')
            : undefined);
    // What is still missing, said next to Book.
    const missing = [
        !existing &&
            !data.new_customer.phone.trim() &&
            t('jobs.quick.missing.phone'),
        !existing &&
            !!data.new_customer.phone.trim() &&
            !phoneValid &&
            t('jobs.quick.missing.valid_phone'),
        !existing &&
            !data.new_customer.first_name.trim() &&
            t('jobs.quick.missing.name'),
        existing && !data.property_id && t('jobs.quick.missing.address'),
        !data.brand_id && t('jobs.quick.missing.brand'),
        booking &&
            (!data.visit.date || !times.start || times.end <= times.start) &&
            t('jobs.quick.missing.time'),
    ].filter((item): item is string => !!item);
    const ready = missing.length === 0;
    const dirty =
        data.new_customer.phone !== '' ||
        data.new_customer.first_name !== '' ||
        data.description !== '' ||
        applianceType !== '';

    const sending = useRef(false);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        // A double tap fires twice before `processing` re-renders: the ref stops the second job.
        if (!ready || form.processing || sending.current) return;
        sending.current = true;
        lookupAbort.current?.abort();
        // An appliance of that type already at the address is linked instead of added twice.
        const known = customer?.properties
            .find((p) => p.id === data.property_id)
            ?.appliances?.find((a) => a.type === applianceType);
        form.transform((d) => ({
            ...d,
            visit: { ...d.visit, start_time: times.start, end_time: times.end },
            ...(applianceType === ''
                ? {}
                : known
                  ? { appliance_ids: [known.id] }
                  : { new_appliances: [{ type: applianceType }] }),
        }));
        form.post(store().url, {
            onFinish: () => {
                sending.current = false;
            },
        });
    };

    const cancel = () => {
        if (dirty && !confirm(t('jobs.quick.discard'))) return;
        if (window.history.length > 1) {
            window.history.back();
        } else {
            router.visit(index().url);
        }
    };
    const openFullForm = () => {
        try {
            sessionStorage.setItem(
                JOB_DRAFT_KEY,
                JSON.stringify({
                    customer_id: data.customer_id,
                    phone: data.new_customer.phone,
                    name: data.new_customer.first_name,
                    description: data.description,
                    notes: data.notes,
                    appliance_type: applianceType,
                }),
            );
        } catch {
            // Without storage the full form simply opens empty.
        }
        router.visit(
            create({
                query: data.customer_id
                    ? { customer_id: data.customer_id, draft: 1 }
                    : { draft: 1 },
            }).url,
        );
    };

    const setVisit = (changes: Partial<typeof data.visit>) =>
        form.setData('visit', { ...data.visit, ...changes });
    const setNewCustomer = (changes: Partial<typeof data.new_customer>) =>
        form.setData('new_customer', { ...data.new_customer, ...changes });
    const setProperty = (changes: Partial<typeof data.new_customer.property>) =>
        setNewCustomer({
            property: { ...data.new_customer.property, ...changes },
        });

    const pickDay = (ymd: string) => {
        setVisit({ date: ymd });
        // A window that already started today, or "Now" on another day, moves to the first open window.
        if (
            (slot.kind === 'now' && ymd !== today) ||
            (slot.kind === 'window' && ymd === today && slot.start <= nowTime)
        ) {
            const first = ymd === today ? upcomingToday[0] : arrivalWindows[0];
            setSlot(first ? { kind: 'window', ...first } : { kind: 'custom' });
        }
    };
    const pickWindow = (start: string) => {
        if (start === 'now') {
            setSlot({ kind: 'now' });
            return;
        }
        const window = arrivalWindows.find((w) => w.start === start);
        if (window) setSlot({ kind: 'window', ...window });
    };
    const hours = useMemo(
        () =>
            new Intl.DateTimeFormat(locale, {
                hour: 'numeric',
                minute: '2-digit',
                timeZone: 'UTC',
            }),
        [locale],
    );
    const range = (w: Window) =>
        hours.formatRange(
            new Date(`1970-01-01T${w.start}:00Z`),
            new Date(`1970-01-01T${w.end}:00Z`),
        );
    // "free" or "busy 9–11" for each technician in the chosen day and time.
    const availability = (userId: number) => {
        const slotTimes = slot.kind === 'now' ? nowRange(userId) : times;
        const clash = (busy[userId] ?? []).find(
            (b) =>
                b.date === data.visit.date &&
                b.start < slotTimes.end &&
                b.end > slotTimes.start,
        );

        return clash
            ? t('jobs.quick.busy', { time: range(clash) })
            : t('jobs.quick.free');
    };
    const pickedOutsideChips =
        data.visit.date > addDays(today, 6) || data.visit.date < today;
    const pickedLabel = new Intl.DateTimeFormat(locale, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${data.visit.date}T00:00:00Z`));
    const title = t(booking ? 'nav.book_customer' : 'invoices.add');
    const assigneeError = Object.entries(errors).find(([key]) =>
        key.startsWith('visit.assignee_ids'),
    )?.[1];

    return (
        <>
            <Head title={title} />
            <ScreenHeader
                title={title}
                leading={
                    <IconButton
                        label={t('common.cancel')}
                        variant="header"
                        onClick={cancel}
                        data-test="booking-cancel"
                    >
                        <X />
                    </IconButton>
                }
                actions={
                    <ActionSheet
                        title={title}
                        items={[
                            {
                                key: 'full-form',
                                label: t('jobs.quick.full_form'),
                                icon: <ExternalLink />,
                                onSelect: openFullForm,
                            },
                        ]}
                    />
                }
            />

            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-5 p-4 sm:p-6"
                data-test="booking-form"
            >
                {brands.length === 0 && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
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

                {existing ? (
                    /* The customer is chosen: one row with Change; their notes stay visible. */
                    <section className="space-y-2" data-test="booking-customer">
                        <div className="da-card flex items-center gap-3 p-3">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold">
                                    {customer.display_name}
                                </span>
                                <span className="block truncate text-sm text-muted-foreground">
                                    {[
                                        phoneText(customer.phone),
                                        customer.properties.find(
                                            (p) => p.id === data.property_id,
                                        )?.full_address,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </span>
                            <Button
                                type="button"
                                variant="tertiary"
                                size="sm"
                                onClick={changeCustomer}
                            >
                                {t('jobs.quick.change')}
                            </Button>
                        </div>
                        <CustomerNotes notes={customer.notes} />
                        {customer.properties.length > 1 && (
                            <FormField
                                id="booking-property"
                                label={t('jobs.fields.property')}
                                error={errors.property_id}
                            >
                                <NativeSelect
                                    id="booking-property"
                                    value={data.property_id ?? ''}
                                    onChange={(event) =>
                                        form.setData(
                                            'property_id',
                                            Number(event.target.value),
                                        )
                                    }
                                >
                                    {customer.properties.map((property) => (
                                        <option
                                            key={property.id}
                                            value={property.id}
                                        >
                                            {property.full_address ||
                                                t('jobs.quick.address_pending')}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                        )}
                        {customer.properties.length === 0 && (
                            <p className="text-sm text-danger">
                                {t('jobs.quick.property_needed')}
                            </p>
                        )}
                        <InputError message={errors.customer_id} />
                    </section>
                ) : (
                    <>
                        <FormField
                            id="booking-phone"
                            label={t('customers.fields.phone')}
                            error={phoneError}
                        >
                            <PhoneInput
                                id="booking-phone"
                                required
                                autoFocus
                                maxLength={32}
                                country={country}
                                value={data.new_customer.phone}
                                aria-invalid={!!phoneError}
                                onBlur={() => setPhoneTouched(true)}
                                onChange={(phone) => {
                                    form.clearErrors('new_customer.phone');
                                    setNewCustomer({ phone });
                                }}
                            />
                            {!nameSearch && (
                                <Matches
                                    matches={matches}
                                    onUse={useCustomer}
                                />
                            )}
                        </FormField>
                        <FormField
                            id="booking-name"
                            label={t('jobs.quick.name')}
                            error={errors['new_customer.first_name']}
                        >
                            <div className="relative">
                                <Input
                                    id="booking-name"
                                    autoComplete="name"
                                    required
                                    maxLength={100}
                                    className="pr-12"
                                    placeholder={
                                        nameSearch
                                            ? t('jobs.quick.search_by_name')
                                            : undefined
                                    }
                                    value={data.new_customer.first_name}
                                    onChange={(event) =>
                                        setNewCustomer({
                                            first_name: event.target.value,
                                        })
                                    }
                                />
                                <button
                                    type="button"
                                    aria-label={t('jobs.quick.find_by_name')}
                                    aria-pressed={nameSearch}
                                    className={cn(
                                        'absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-[14px] text-muted-foreground',
                                        nameSearch && 'text-link',
                                    )}
                                    onClick={() => setNameSearch(!nameSearch)}
                                >
                                    <Search className="size-5" />
                                </button>
                            </div>
                            {nameSearch && (
                                <Matches
                                    matches={matches}
                                    onUse={useCustomer}
                                />
                            )}
                        </FormField>
                    </>
                )}

                {tracksAppliances && (
                    <Step
                        title={t('jobs.quick.appliance')}
                        hint={t('jobs.quick.appliance_optional')}
                    >
                        <div className="max-h-56 overflow-y-auto rounded-2xl p-0.5">
                            <ApplianceTypePicker
                                compact
                                label={t('jobs.quick.appliance')}
                                options={applianceTypes}
                                value={applianceType}
                                onChange={(type) =>
                                    setApplianceType(
                                        type === applianceType ? '' : type,
                                    )
                                }
                            />
                        </div>
                        <InputError
                            message={
                                errors['new_appliances.0.type'] ??
                                errors.appliance_ids
                            }
                        />
                    </Step>
                )}

                <FormField
                    id="booking-problem"
                    label={t('jobs.quick.problem')}
                    error={errors.description}
                >
                    <Textarea
                        id="booking-problem"
                        rows={2}
                        className="[field-sizing:content] min-h-16"
                        placeholder={t('jobs.quick.problem_hint')}
                        value={data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                </FormField>

                {booking && (
                    <Step title={t('jobs.quick.when')}>
                        <div className="flex items-center gap-2">
                            <DateChips
                                className="min-w-0 flex-1"
                                label={t('jobs.quick.day')}
                                today={today}
                                value={data.visit.date}
                                onChange={pickDay}
                            />
                            <label
                                className="da-chip da-press relative inline-flex h-11 shrink-0 cursor-pointer items-center gap-1.5 rounded-2xl px-3 text-sm font-semibold whitespace-nowrap"
                                data-state={pickedOutsideChips ? 'on' : 'off'}
                            >
                                <CalendarDays className="size-4" />
                                {pickedOutsideChips
                                    ? pickedLabel
                                    : t('jobs.quick.pick_date')}
                                <input
                                    type="date"
                                    min={today}
                                    value={data.visit.date}
                                    aria-label={t('jobs.quick.pick_date')}
                                    className="absolute inset-0 cursor-pointer opacity-0"
                                    onClick={(e) => {
                                        try {
                                            e.currentTarget.showPicker();
                                        } catch {
                                            // Older browsers open it by themselves.
                                        }
                                    }}
                                    onChange={(e) =>
                                        e.target.value &&
                                        pickDay(e.target.value)
                                    }
                                />
                            </label>
                        </div>
                        <TimeWindowChips
                            label={t('jobs.quick.arrival_window')}
                            windows={arrivalWindows}
                            nowTime={isToday ? nowTime : null}
                            leading={
                                isToday
                                    ? {
                                          value: 'now',
                                          label: t('jobs.quick.now'),
                                      }
                                    : undefined
                            }
                            value={
                                slot.kind === 'now'
                                    ? 'now'
                                    : slot.kind === 'window'
                                      ? slot.start
                                      : null
                            }
                            onChange={pickWindow}
                        />
                        {slot.kind === 'now' && (
                            <p className="text-xs text-muted-foreground">
                                {t('jobs.quick.now_hint', {
                                    time: range(times),
                                })}
                            </p>
                        )}
                        {slot.kind === 'custom' ? (
                            <div className="grid grid-cols-2 gap-3">
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
                                        value={data.visit.date}
                                        onChange={(event) =>
                                            setVisit({
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
                                        value={data.visit.start_time}
                                        onChange={(event) =>
                                            setVisit({
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
                                        value={data.visit.end_time}
                                        onChange={(event) =>
                                            setVisit({
                                                end_time: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            </div>
                        ) : (
                            <button
                                type="button"
                                className="min-h-11 text-sm font-medium text-link"
                                onClick={() => {
                                    setVisit({
                                        start_time: times.start,
                                        end_time: times.end,
                                    });
                                    setSlot({ kind: 'custom' });
                                }}
                            >
                                {t('jobs.quick.custom_time')}
                            </button>
                        )}
                        <InputError
                            message={
                                errors['visit.date'] ??
                                errors['visit.start_time'] ??
                                errors['visit.end_time']
                            }
                        />
                    </Step>
                )}

                {booking && bookingTechnicians.length > 0 && (
                    <Step title={t('jobs.quick.technician')}>
                        <div
                            role="radiogroup"
                            aria-label={t('jobs.quick.technician')}
                            className="flex flex-wrap gap-2"
                        >
                            {[
                                ...bookingTechnicians.map((user) => ({
                                    id: user.id as number | null,
                                    name: user.name,
                                    hint: availability(user.id),
                                })),
                                {
                                    id: null,
                                    name: t('calendar.unassigned'),
                                    hint: '',
                                },
                            ].map((choice) => (
                                <button
                                    key={choice.id ?? 'none'}
                                    type="button"
                                    role="radio"
                                    aria-checked={technicianId === choice.id}
                                    aria-pressed={technicianId === choice.id}
                                    onClick={() =>
                                        setVisit({
                                            assignee_ids:
                                                choice.id === null
                                                    ? []
                                                    : [choice.id],
                                        })
                                    }
                                    className="da-chip da-press flex min-h-12 flex-col items-start justify-center rounded-2xl px-4 text-left"
                                >
                                    <span className="text-sm font-semibold">
                                        {choice.name}
                                    </span>
                                    {choice.hint && (
                                        <span className="text-xs opacity-80">
                                            {choice.hint}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <InputError message={assigneeError} />
                    </Step>
                )}

                {!existing && (
                    <Step
                        title={t('jobs.quick.address')}
                        hint={t('jobs.quick.address_optional')}
                    >
                        <AddressAutocomplete
                            id="booking-address"
                            aria-label={t('jobs.quick.address')}
                            autoComplete="street-address"
                            value={data.new_customer.property.line1}
                            country={
                                data.new_customer.property.country || country
                            }
                            onChange={(value) =>
                                setProperty({ line1: value, ...clearedPlace })
                            }
                            onPick={(address) => {
                                setProperty({
                                    ...address,
                                    unit:
                                        address.unit ??
                                        data.new_customer.property.unit,
                                });
                                setAddressOpen(true);
                            }}
                        />
                        <InputError
                            message={
                                errors['new_customer.property.line1'] ??
                                errors['new_customer.property.city']
                            }
                        />
                        {addressOpen ? (
                            <div className="grid grid-cols-2 gap-3">
                                <FormField
                                    id="booking-unit"
                                    label={t('jobs.quick.unit')}
                                    error={errors['new_customer.property.unit']}
                                >
                                    <Input
                                        id="booking-unit"
                                        autoComplete="address-line2"
                                        maxLength={50}
                                        value={data.new_customer.property.unit}
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
                                >
                                    <Input
                                        id="booking-city"
                                        autoComplete="address-level2"
                                        maxLength={100}
                                        value={data.new_customer.property.city}
                                        onChange={(event) =>
                                            setProperty({
                                                city: event.target.value,
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
                                onClick={() => setAddressOpen(true)}
                            >
                                <MapPin className="size-4" />
                                {t('jobs.quick.enter_manually')}
                            </button>
                        )}
                    </Step>
                )}

                {/* More details, folded: about the customer, brand, source, notes for the team. */}
                <details className="da-card group" data-test="booking-more">
                    <summary className="flex min-h-12 cursor-pointer list-none items-center gap-2 px-4 text-sm font-semibold [&::-webkit-details-marker]:hidden">
                        <span className="flex-1">
                            {t('jobs.quick.more_details')}
                        </span>
                        <ChevronDown className="size-5 transition-transform group-open:rotate-180" />
                    </summary>
                    <div className="space-y-3 border-t p-4">
                        {!existing && (
                            <FormField
                                id="booking-notes"
                                label={t('jobs.quick.customer_notes')}
                                error={errors['new_customer.notes']}
                            >
                                <Input
                                    id="booking-notes"
                                    value={data.new_customer.notes}
                                    onChange={(event) =>
                                        setNewCustomer({
                                            notes: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        )}
                        {brands.length > 1 && (
                            <FormField
                                id="booking-brand"
                                label={t('jobs.fields.brand')}
                                error={errors.brand_id}
                            >
                                <NativeSelect
                                    id="booking-brand"
                                    value={data.brand_id}
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
                        {leadSources.length > 0 && (
                            <FormField
                                id="booking-source"
                                label={t('jobs.fields.lead_source')}
                                error={errors.lead_source}
                            >
                                <NativeSelect
                                    id="booking-source"
                                    value={data.lead_source}
                                    onChange={(event) =>
                                        form.setData(
                                            'lead_source',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">—</option>
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
                        )}
                        <FormField
                            id="booking-team-notes"
                            label={t('jobs.fields.notes')}
                            error={errors.notes}
                        >
                            <Textarea
                                id="booking-team-notes"
                                rows={2}
                                className="[field-sizing:content] min-h-16"
                                value={data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                </details>
                {brands.length <= 1 && <InputError message={errors.brand_id} />}

                {Object.entries(errors)
                    .filter(
                        ([key]) =>
                            ![
                                'brand_id',
                                'customer_id',
                                'property_id',
                                'description',
                                'notes',
                                'lead_source',
                                'new_customer.first_name',
                                'new_customer.phone',
                                'new_customer.notes',
                                'new_customer.property.line1',
                                'new_customer.property.city',
                                'new_customer.property.unit',
                                'visit.date',
                                'visit.start_time',
                                'visit.end_time',
                                'new_appliances.0.type',
                                'appliance_ids',
                            ].includes(key) &&
                            !key.startsWith('visit.assignee_ids'),
                    )
                    .map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}

                <StickyActionBar>
                    <div className="flex w-full items-center gap-3">
                        <p
                            role="status"
                            data-test="booking-status"
                            className={cn(
                                'min-w-0 flex-1 text-sm',
                                ready
                                    ? 'font-semibold text-success'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {ready
                                ? t('jobs.quick.ready')
                                : t('jobs.quick.missing.intro', {
                                      items: missing.join(', '),
                                  })}
                        </p>
                        <Button
                            type="submit"
                            size="lg"
                            className="shrink-0 px-8"
                            disabled={!ready}
                            loading={form.processing}
                        >
                            {t(
                                booking
                                    ? 'jobs.quick.book'
                                    : 'jobs.quick.continue_invoice',
                            )}
                        </Button>
                    </div>
                </StickyActionBar>
            </form>
        </>
    );
}
