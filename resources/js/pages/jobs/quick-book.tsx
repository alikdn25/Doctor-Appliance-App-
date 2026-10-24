import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronDown,
    ClipboardPaste,
    ExternalLink,
    Search,
    X,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ApplianceTypePicker } from '@/components/appliance-image';
import {
    AddressEntry,
    addressComplete,
    emptyAddress,
} from '@/components/customers/address-entry';
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
import { MonthCalendar } from '@/components/ui/month-calendar';
import { NativeSelect } from '@/components/ui/native-select';
import { PhoneInput } from '@/components/ui/phone-input';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { Textarea } from '@/components/ui/textarea';
import { TimeWindowChips } from '@/components/ui/time-window-chips';
import { useTrans } from '@/lib/i18n';
import { isPossiblePhone, usePhone } from '@/lib/phone';
import { cn } from '@/lib/utils';
import { index as brandsPage } from '@/routes/brands';
import { create, index, lookup, parseMessage, store } from '@/routes/jobs';
import { xsrfToken } from '@/lib/http';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { toast } from 'sonner';
import type { Option } from '@/types';

type Occupant = {
    name: string | null;
    phone: string | null;
    access_notes: string | null;
};

type BillingParts = {
    line1: string | null;
    unit: string | null;
    city: string | null;
    region: string | null;
    postal_code: string | null;
    country: string | null;
};

type CustomerChoice = {
    id: number;
    display_name: string;
    phone: string | null;
    email?: string | null;
    billing?: BillingParts | null;
    notes: string | null;
    jobs_count?: number;
    properties: {
        id: number;
        full_address: string;
        is_primary: boolean;
        address?: { line1: string };
        occupant?: Occupant;
        appliances?: { id: number; type: string }[];
    }[];
};

/** What "Paste from message" read from the text (server: BookingTextParser). */
type ParsedAddress = {
    line1: string;
    unit: string | null;
    city: string | null;
    region: string | null;
    postal_code: string | null;
};
type Parsed = {
    source: 'ai' | 'basic';
    customer: CustomerChoice | null;
    fields: {
        first_name: string | null;
        last_name: string | null;
        company_name: string | null;
        phone: string | null;
        email: string | null;
        job_address: ParsedAddress | null;
        billing_address: ParsedAddress | null;
        occupant_name: string | null;
        occupant_phone: string | null;
        access_notes: string | null;
        appliance_type: string | null;
        appliance_brand: string | null;
        problem: string | null;
    };
};

/** An on/off switch as a whole raised row (one tap). */
function SwitchRow({
    label,
    checked,
    onChange,
    testId,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    testId?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            data-test={testId}
            className="da-card da-press flex min-h-12 w-full items-center gap-3 px-4 py-2 text-left text-sm font-semibold"
        >
            <span className="min-w-0 flex-1">{label}</span>
            <span
                aria-hidden="true"
                className={cn(
                    'relative h-7 w-12 shrink-0 rounded-full transition-colors',
                    checked ? 'bg-[#1e8cfd]' : 'da-track',
                )}
            >
                <span
                    className={cn(
                        'da-raised absolute top-0.5 size-6 rounded-full bg-white transition-all',
                        checked ? 'left-[22px]' : 'left-0.5',
                    )}
                />
            </span>
        </button>
    );
}

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

type PropertyChoice = CustomerChoice['properties'][number];

/** The customer's primary real address (or the first one). */
const initialPrimary = (customer: CustomerChoice | null) => {
    const usable = (customer?.properties ?? []).filter(
        (p) => (p.address?.line1 ?? p.full_address) !== '',
    );

    return usable.find((p) => p.is_primary) ?? usable[0];
};

/** The occupant saved at an address: the switch is on when there is one. */
const occupantOf = (property: PropertyChoice | undefined) => {
    const o = property?.occupant;

    return {
        enabled: !!(o?.name || o?.phone),
        name: o?.name ?? '',
        phone: o?.phone ?? '',
        access_notes: o?.access_notes ?? '',
    };
};

/** The customer's own billing address, or empty (= the same as the primary address). */
const billingOf = (customer: CustomerChoice | null, country: string) => ({
    ...emptyAddress(customer?.billing?.country ?? country),
    line1: customer?.billing?.line1 ?? '',
    unit: customer?.billing?.unit ?? '',
    city: customer?.billing?.city ?? '',
    region: customer?.billing?.region ?? '',
    postal_code: customer?.billing?.postal_code ?? '',
});

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
            last_name: '',
            phone: '',
            email: '',
            notes: '',
            property: emptyAddress(country),
        },
        // Someone else lives at the job address (a tenant): saved with the address and offered next time.
        occupant: occupantOf(initialPrimary(initialCustomer)),
        // The payer's billing address (empty = the same as the job / primary address).
        billing: billingOf(initialCustomer, country),
        // Address typed for a customer from the book who has none yet.
        new_property: emptyAddress(country),
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
    // Addresses of the chosen customer that are real addresses (an empty one is filled in instead).
    const addresses = (customer?.properties ?? []).filter(
        (p) => (p.address?.line1 ?? p.full_address) !== '',
    );
    // "New address" for a customer from the book: typed in place and saved to the customer.
    const [newAddress, setNewAddress] = useState(false);
    const needsAddress = existing && (addresses.length === 0 || newAddress);
    const occupantOn = data.occupant.enabled;
    const [calendarOpen, setCalendarOpen] = useState(false);
    const [pasteOpen, setPasteOpen] = useState(false);
    const [pasteText, setPasteText] = useState('');
    const [pasting, setPasting] = useState(false);
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
    const nameTerm =
        `${data.new_customer.first_name} ${data.new_customer.last_name}`.trim();
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
        const usable = choice.properties.filter(
            (p) => (p.address?.line1 ?? p.full_address) !== '',
        );
        const property = usable.find((p) => p.is_primary) ?? usable[0];
        setNewAddress(false);
        form.setData((d) => ({
            ...d,
            new_customer_mode: false,
            customer_id: choice.id,
            property_id: property?.id ?? null,
            occupant: occupantOf(property),
            billing: billingOf(choice, country),
        }));
    };
    const pickProperty = (id: number) => {
        setNewAddress(false);
        form.setData((d) => ({
            ...d,
            property_id: id,
            occupant: occupantOf(customer?.properties.find((p) => p.id === id)),
        }));
    };
    const changeCustomer = () => {
        setCustomer(null);
        setNewAddress(false);
        form.setData((d) => ({
            ...d,
            new_customer_mode: true,
            customer_id: null,
            property_id: null,
            occupant: occupantOf(undefined),
            billing: emptyAddress(country),
        }));
    };
    const setOccupant = (changes: Partial<typeof data.occupant>) =>
        form.setData('occupant', { ...data.occupant, ...changes });
    const setBilling = (changes: Partial<typeof data.billing>) =>
        form.setData('billing', { ...data.billing, ...changes });

    // "Paste from message": the server reads the text (the company's AI, or a basic reading) and the form is
    // filled for the person to check. A phone already in the book brings that customer.
    const applyParsed = (parsed: Parsed) => {
        const f = parsed.fields;
        const address = (a: ParsedAddress | null) =>
            a
                ? {
                      ...emptyAddress(country),
                      line1: a.line1,
                      unit: a.unit ?? '',
                      city: a.city ?? '',
                      region: a.region ?? '',
                      postal_code: a.postal_code ?? '',
                  }
                : null;
        const jobAddress = address(f.job_address);
        const billing = address(f.billing_address);
        const occupant =
            f.occupant_name || f.occupant_phone
                ? {
                      enabled: true,
                      name: f.occupant_name ?? '',
                      phone: f.occupant_phone ?? '',
                      access_notes: f.access_notes ?? '',
                  }
                : null;
        if (f.appliance_type) setApplianceType(f.appliance_type);

        if (parsed.customer) {
            const choice = parsed.customer;
            setCustomer(choice);
            setMatches([]);
            const known = choice.properties.find(
                (p) =>
                    jobAddress !== null &&
                    p.address?.line1.trim().toLowerCase() ===
                        jobAddress.line1.trim().toLowerCase(),
            );
            const usable = choice.properties.filter(
                (p) => (p.address?.line1 ?? p.full_address) !== '',
            );
            const property =
                known ??
                (jobAddress
                    ? undefined
                    : (usable.find((p) => p.is_primary) ?? usable[0]));
            setNewAddress(!property && jobAddress !== null);
            form.setData((d) => ({
                ...d,
                new_customer_mode: false,
                customer_id: choice.id,
                property_id: property?.id ?? null,
                new_property:
                    !property && jobAddress ? jobAddress : d.new_property,
                description: f.problem ?? d.description,
                occupant: occupant ?? occupantOf(property),
                billing: billing ?? billingOf(choice, country),
            }));

            return;
        }

        form.setData((d) => ({
            ...d,
            new_customer: {
                ...d.new_customer,
                first_name:
                    f.first_name ?? f.company_name ?? d.new_customer.first_name,
                last_name: f.last_name ?? d.new_customer.last_name,
                phone: f.phone ?? d.new_customer.phone,
                email: f.email ?? d.new_customer.email,
                property: jobAddress ?? d.new_customer.property,
            },
            description: f.problem ?? d.description,
            occupant: occupant ?? d.occupant,
            billing: billing ?? d.billing,
        }));
    };
    const readMessage = () => {
        if (pasteText.trim() === '' || pasting) return;
        setPasting(true);
        fetch(parseMessage().url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ text: pasteText }),
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((parsed: Parsed) => {
                applyParsed(parsed);
                setPasteOpen(false);
                setPasteText('');
                toast.success(
                    parsed.customer
                        ? t('jobs.quick.paste_known', {
                              name: parsed.customer.display_name,
                          })
                        : t(
                              parsed.source === 'ai'
                                  ? 'jobs.quick.paste_filled'
                                  : 'jobs.quick.paste_basic',
                          ),
                );
            })
            .catch(() => toast.error(t('jobs.quick.paste_failed')))
            .finally(() => setPasting(false));
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
        !existing && !nameTerm && t('jobs.quick.missing.name'),
        ((!existing && !addressComplete(data.new_customer.property)) ||
            (needsAddress && !addressComplete(data.new_property)) ||
            (existing && !needsAddress && !data.property_id)) &&
            t('jobs.quick.missing.address'),
        !data.brand_id && t('jobs.quick.missing.brand'),
        booking &&
            (!data.visit.date || !times.start || times.end <= times.start) &&
            t('jobs.quick.missing.time'),
    ].filter((item): item is string => !!item);
    const ready = missing.length === 0;
    const dirty =
        data.new_customer.phone !== '' ||
        nameTerm !== '' ||
        data.new_customer.property.line1 !== '' ||
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
        form.transform(({ new_property, billing, ...d }) => ({
            ...d,
            ...(needsAddress ? { new_property, property_id: null } : {}),
            // The billing address goes with the switch: off = the job address is the customer's own.
            ...(occupantOn ? { billing } : {}),
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
                    name: nameTerm,
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
    const setNewProperty = (changes: Partial<typeof data.new_property>) =>
        form.setData('new_property', { ...data.new_property, ...changes });

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

                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={() => setPasteOpen(true)}
                    data-test="booking-paste"
                >
                    <ClipboardPaste /> {t('jobs.quick.paste')}
                </Button>

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
                        {addresses.length > 0 && (
                            <Step title={t('jobs.quick.job_address')}>
                                <div
                                    role="radiogroup"
                                    aria-label={t('jobs.quick.job_address')}
                                    className="space-y-2"
                                    data-test="booking-properties"
                                >
                                    {addresses.map((property) => (
                                        <button
                                            key={property.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={
                                                !newAddress &&
                                                data.property_id === property.id
                                            }
                                            onClick={() =>
                                                pickProperty(property.id)
                                            }
                                            className="da-chip da-press flex min-h-12 w-full flex-col items-start justify-center rounded-2xl px-4 py-2 text-left text-sm"
                                        >
                                            <span className="font-semibold">
                                                {property.full_address}
                                            </span>
                                            {property.occupant?.name && (
                                                <span className="text-xs opacity-80">
                                                    {t(
                                                        'properties.site_contact',
                                                    )}
                                                    : {property.occupant.name}
                                                </span>
                                            )}
                                        </button>
                                    ))}
                                    <button
                                        type="button"
                                        role="radio"
                                        aria-checked={newAddress}
                                        onClick={() => {
                                            setNewAddress(true);
                                            setOccupant({
                                                enabled: false,
                                                name: '',
                                                phone: '',
                                                access_notes: '',
                                            });
                                        }}
                                        className="da-chip da-press flex min-h-12 w-full items-center rounded-2xl px-4 text-left text-sm font-semibold"
                                        data-test="booking-new-address"
                                    >
                                        + {t('jobs.quick.new_address')}
                                    </button>
                                </div>
                                <InputError message={errors.property_id} />
                            </Step>
                        )}
                        {needsAddress && (
                            <AddressEntry
                                id="booking-address"
                                label={t('jobs.quick.job_address')}
                                value={data.new_property}
                                errors={errors}
                                errorPrefix="new_property."
                                onChange={setNewProperty}
                            />
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
                        <div className="grid grid-cols-2 gap-3">
                            <FormField
                                id="booking-name"
                                label={t('customers.fields.first_name')}
                                error={errors['new_customer.first_name']}
                            >
                                <Input
                                    id="booking-name"
                                    autoComplete="given-name"
                                    maxLength={100}
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
                            </FormField>
                            <FormField
                                id="booking-last-name"
                                label={t('customers.fields.last_name')}
                                error={errors['new_customer.last_name']}
                            >
                                <div className="relative">
                                    <Input
                                        id="booking-last-name"
                                        autoComplete="family-name"
                                        maxLength={100}
                                        className="pr-12"
                                        value={data.new_customer.last_name}
                                        onChange={(event) =>
                                            setNewCustomer({
                                                last_name: event.target.value,
                                            })
                                        }
                                    />
                                    <button
                                        type="button"
                                        aria-label={t(
                                            'jobs.quick.find_by_name',
                                        )}
                                        aria-pressed={nameSearch}
                                        className={cn(
                                            'absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-[14px] text-muted-foreground',
                                            nameSearch && 'text-link',
                                        )}
                                        onClick={() =>
                                            setNameSearch(!nameSearch)
                                        }
                                    >
                                        <Search className="size-5" />
                                    </button>
                                </div>
                            </FormField>
                        </div>
                        {nameSearch && (
                            <Matches matches={matches} onUse={useCustomer} />
                        )}
                        <FormField
                            id="booking-email"
                            label={t('jobs.quick.email')}
                            error={errors['new_customer.email']}
                        >
                            <Input
                                id="booking-email"
                                type="email"
                                inputMode="email"
                                autoComplete="email"
                                maxLength={255}
                                value={data.new_customer.email}
                                onChange={(event) =>
                                    setNewCustomer({
                                        email: event.target.value,
                                    })
                                }
                            />
                        </FormField>
                        {/* The address comes right after the name: a booking always has one. */}
                        <AddressEntry
                            id="booking-address"
                            label={t('jobs.quick.job_address')}
                            value={data.new_customer.property}
                            errors={errors}
                            errorPrefix="new_customer.property."
                            onChange={setProperty}
                        />
                    </>
                )}

                {/* Not at the customer's address / someone else lives there: the occupant of this address and the
                    payer's own billing address. */}
                <section className="space-y-3" data-test="booking-occupant">
                    <SwitchRow
                        label={t('jobs.quick.occupant_toggle')}
                        checked={occupantOn}
                        onChange={(enabled) => setOccupant({ enabled })}
                        testId="booking-occupant-toggle"
                    />
                    {occupantOn && (
                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <FormField
                                    id="booking-occupant-name"
                                    label={t('jobs.quick.occupant_name')}
                                    error={errors['occupant.name']}
                                >
                                    <Input
                                        id="booking-occupant-name"
                                        autoComplete="off"
                                        maxLength={255}
                                        value={data.occupant.name}
                                        onChange={(event) =>
                                            setOccupant({
                                                name: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="booking-occupant-phone"
                                    label={t('jobs.quick.occupant_phone')}
                                    error={errors['occupant.phone']}
                                >
                                    <PhoneInput
                                        id="booking-occupant-phone"
                                        maxLength={32}
                                        country={country}
                                        value={data.occupant.phone}
                                        onChange={(phone) =>
                                            setOccupant({ phone })
                                        }
                                    />
                                </FormField>
                            </div>
                            <FormField
                                id="booking-access"
                                label={t('jobs.quick.access_notes')}
                                error={errors['occupant.access_notes']}
                            >
                                <Input
                                    id="booking-access"
                                    maxLength={2000}
                                    placeholder={t('jobs.quick.access_hint')}
                                    value={data.occupant.access_notes}
                                    onChange={(event) =>
                                        setOccupant({
                                            access_notes: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            <AddressEntry
                                id="booking-billing"
                                label={t('jobs.quick.billing_address')}
                                value={data.billing}
                                errors={errors}
                                errorPrefix="billing."
                                optional
                                onChange={setBilling}
                            />
                            <p className="-mt-1 text-xs text-muted-foreground">
                                {t('jobs.quick.billing_hint')}
                            </p>
                        </div>
                    )}
                </section>

                {tracksAppliances && (
                    <Step
                        title={t('jobs.quick.appliance')}
                        hint={t('jobs.quick.appliance_optional')}
                    >
                        <div className="rounded-2xl p-0.5">
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
                                trailing={
                                    <button
                                        type="button"
                                        aria-expanded={calendarOpen}
                                        className="da-chip da-press inline-flex h-11 shrink-0 items-center gap-1.5 rounded-2xl px-3 text-sm font-semibold whitespace-nowrap"
                                        data-state={
                                            pickedOutsideChips || calendarOpen
                                                ? 'on'
                                                : 'off'
                                        }
                                        data-test="booking-pick-date"
                                        onClick={() =>
                                            setCalendarOpen(!calendarOpen)
                                        }
                                    >
                                        <CalendarDays className="size-4" />
                                        {pickedOutsideChips
                                            ? pickedLabel
                                            : t('jobs.quick.pick_date')}
                                    </button>
                                }
                            />
                        </div>
                        {/* In the page flow under the day row: it never covers the days. */}
                        {calendarOpen && (
                            <MonthCalendar
                                label={t('jobs.quick.pick_date')}
                                value={data.visit.date}
                                min={today}
                                onChange={(ymd) => {
                                    pickDay(ymd);
                                    setCalendarOpen(false);
                                }}
                            />
                        )}
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

                {booking &&
                    bookingTechnicians.length > 0 &&
                    !auth.company?.works_alone && (
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
                                        aria-checked={
                                            technicianId === choice.id
                                        }
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
                                'new_customer.last_name',
                                'new_customer.phone',
                                'new_customer.notes',
                                'new_customer.email',
                                'occupant.name',
                                'occupant.phone',
                                'occupant.access_notes',
                                'billing.line1',
                                'billing.city',
                                'new_customer.property.line1',
                                'new_customer.property.city',
                                'new_customer.property.unit',
                                'new_customer.property.region',
                                'new_customer.property.postal_code',
                                'new_property.line1',
                                'new_property.city',
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

            <Sheet open={pasteOpen} onOpenChange={setPasteOpen}>
                <SheetContent
                    side="bottom"
                    className="mx-auto flex max-h-[85dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                    data-test="booking-paste-sheet"
                >
                    <SheetHeader className="p-0 pr-8">
                        <SheetTitle>{t('jobs.quick.paste_title')}</SheetTitle>
                        <SheetDescription>
                            {t('jobs.quick.paste_hint')}
                        </SheetDescription>
                    </SheetHeader>
                    <Textarea
                        id="booking-paste-text"
                        aria-label={t('jobs.quick.paste_title')}
                        rows={6}
                        maxLength={5000}
                        className="min-h-36"
                        value={pasteText}
                        onChange={(event) => setPasteText(event.target.value)}
                    />
                    <Button
                        type="button"
                        size="lg"
                        disabled={pasteText.trim() === ''}
                        loading={pasting}
                        onClick={readMessage}
                        data-test="booking-paste-fill"
                    >
                        {t('jobs.quick.paste_fill')}
                    </Button>
                </SheetContent>
            </Sheet>
        </>
    );
}
