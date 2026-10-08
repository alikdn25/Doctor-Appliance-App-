import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    Download,
    MapPin,
    Plus,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useMoney } from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showJob } from '@/routes/jobs';
import {
    destroy,
    exportMethod,
    index,
    startAddress as saveStartAddress,
    store,
    update,
} from '@/routes/trips';
import type { Option } from '@/types';

type TripRow = {
    id: number;
    date: string;
    type: string;
    from: string | null;
    to: string | null;
    distance: number | null;
    purpose: string | null;
    driver: string | null;
    job: { id: number; number: number } | null;
    auto: boolean;
};

type Totals = { distance: number; amount: number | null };

type TripForm = {
    trip_date: string;
    type: string;
    from_address: string;
    to_address: string;
    distance: string;
    purpose: string;
};

const shiftMonth = (month: string, by: number) => {
    const [y, m] = month.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1 + by, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
};

/**
 * Mileage log: the month's trips with totals for the month and the year, "+ Trip" for one quick entry,
 * corrections of the trips made from the route and the CSV log for taxes.
 */
export default function Trips({
    month,
    person,
    unit,
    rate,
    currency,
    trips,
    totals,
    startAddress,
    people,
    types,
    today,
}: {
    month: string;
    person: string | null;
    unit: 'km' | 'mi';
    rate: number | null;
    currency: string;
    trips: TripRow[];
    totals: { month: Totals; year: Totals };
    startAddress: string | null;
    people: Option[];
    types: Option[];
    today: string;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const { auth } = usePage().props;
    const money = useMoney(currency);
    const [editing, setEditing] = useState<TripRow | null>(null);
    const [open, setOpen] = useState(false);
    const [startOpen, setStartOpen] = useState(false);
    const start = useForm({ address: startAddress ?? '' });
    const unitLabel = t(`trips.units.${unit}`);
    const typeLabel = (value: string) =>
        types.find((type) => type.value === value)?.label ?? value;
    const query = (extra: Record<string, string>) => ({
        ...(person ? { person } : {}),
        month,
        ...extra,
    });

    const form = useForm<TripForm>({
        trip_date: today,
        type: 'parts_store',
        from_address: '',
        to_address: '',
        distance: '',
        purpose: '',
    });

    const openForm = (trip: TripRow | null) => {
        setEditing(trip);
        form.clearErrors();
        form.setData(
            trip
                ? {
                      trip_date: trip.date,
                      type: trip.type,
                      from_address: trip.from ?? '',
                      to_address: trip.to ?? '',
                      distance:
                          trip.distance === null ? '' : String(trip.distance),
                      purpose: trip.purpose ?? '',
                  }
                : {
                      trip_date: today,
                      type: 'parts_store',
                      from_address: '',
                      to_address: '',
                      distance: '',
                      purpose: '',
                  },
        );
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            distance: d.distance.replace(',', '.').trim(),
        }));
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            form.put(update(editing.id).url, options);
        } else {
            form.post(store().url, options);
        }
    };

    const remove = () => {
        if (editing && confirm(t('trips.confirm_remove'))) {
            router.delete(destroy(editing.id).url, {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
            });
        }
    };

    const totalCard = (label: string, value: Totals) => (
        <div className="da-card min-w-0 p-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-lg font-semibold whitespace-nowrap tabular-nums">
                {value.distance.toFixed(1)} {unitLabel}
            </p>
            {value.amount !== null && (
                <p className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">
                    {money(value.amount)}
                </p>
            )}
        </div>
    );

    return (
        <>
            <Head title={t('trips.title')} />

            <div className="max-w-3xl space-y-4 p-4 pb-24">
                <PageHeader
                    title={t('trips.title')}
                    description={t('trips.description')}
                    actions={
                        <Button className="h-11" onClick={() => openForm(null)}>
                            <Plus /> {t('trips.add').replace('+ ', '')}
                        </Button>
                    }
                />

                <div className="flex items-center gap-2">
                    <Button variant="outline" size="icon" asChild>
                        <Link
                            href={index({
                                query: query({ month: shiftMonth(month, -1) }),
                            })}
                            aria-label={t('calendar.previous')}
                        >
                            <ChevronLeft />
                        </Link>
                    </Button>
                    <span className="flex-1 text-center font-medium">
                        {new Intl.DateTimeFormat(
                            auth.company?.locale ?? 'en-US',
                            { month: 'long', year: 'numeric', timeZone: 'UTC' },
                        ).format(new Date(`${month}-01T00:00:00Z`))}
                    </span>
                    <Button variant="outline" size="icon" asChild>
                        <Link
                            href={index({
                                query: query({ month: shiftMonth(month, 1) }),
                            })}
                            aria-label={t('calendar.next')}
                        >
                            <ChevronRight />
                        </Link>
                    </Button>
                </div>

                <div className="grid grid-cols-2 gap-2">
                    {totalCard(t('trips.month_total'), totals.month)}
                    {totalCard(
                        t('trips.year_total', { year: month.slice(0, 4) }),
                        totals.year,
                    )}
                </div>
                {rate === null && (
                    <p className="text-xs text-muted-foreground">
                        {t('trips.no_rate', { unit: unitLabel })}
                    </p>
                )}

                {people.length > 0 && (
                    <NativeSelect
                        aria-label={t('trips.fields.person')}
                        value={person ?? ''}
                        onChange={(e) =>
                            router.get(
                                index().url,
                                {
                                    month,
                                    ...(e.target.value
                                        ? { person: e.target.value }
                                        : {}),
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <option value="">{t('trips.everyone')}</option>
                        {people.map((p) => (
                            <option key={p.value} value={p.value}>
                                {p.label}
                            </option>
                        ))}
                    </NativeSelect>
                )}

                {startOpen ? (
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            start.put(saveStartAddress().url, {
                                preserveScroll: true,
                                onSuccess: () => setStartOpen(false),
                            });
                        }}
                    >
                        <FormField
                            id="start-address"
                            label={t('trips.start_address')}
                            hint={t('trips.start_address_hint')}
                            error={start.errors.address}
                        >
                            <Input
                                id="start-address"
                                value={start.data.address}
                                maxLength={255}
                                onChange={(e) =>
                                    start.setData('address', e.target.value)
                                }
                            />
                        </FormField>
                        <Button
                            type="submit"
                            className="h-11"
                            disabled={start.processing}
                        >
                            {t('common.save')}
                        </Button>
                    </form>
                ) : (
                    <button
                        type="button"
                        className="flex min-h-11 w-full items-center gap-2 text-left text-sm"
                        onClick={() => setStartOpen(true)}
                    >
                        <MapPin className="size-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0 flex-1 truncate">
                            {t('trips.start_address')}: {startAddress ?? '—'}
                        </span>
                        <span className="font-medium text-primary">
                            {t('common.edit')}
                        </span>
                    </button>
                )}

                {trips.length === 0 ? (
                    <p className="da-card p-8 text-center text-sm text-muted-foreground">
                        {t('trips.empty')}
                    </p>
                ) : (
                    <ul className="da-card divide-y overflow-hidden">
                        {trips.map((trip) => (
                            <li key={trip.id}>
                                <button
                                    type="button"
                                    className="flex min-h-16 w-full items-start gap-3 p-3 text-left hover:bg-muted/40"
                                    onClick={() => openForm(trip)}
                                >
                                    <div className="min-w-0 flex-1 space-y-0.5">
                                        <p className="text-sm font-medium">
                                            {time.dateOnly(trip.date)} ·{' '}
                                            {typeLabel(trip.type)}
                                            {trip.driver && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {trip.driver}
                                                </span>
                                            )}
                                        </p>
                                        <p className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                                            <span className="truncate">
                                                {trip.from ?? '—'}
                                            </span>
                                            <ArrowRight className="size-3 shrink-0" />
                                            <span className="truncate">
                                                {trip.to ?? '—'}
                                            </span>
                                        </p>
                                        {trip.purpose && (
                                            <p className="truncate text-xs">
                                                {trip.purpose}
                                            </p>
                                        )}
                                    </div>
                                    <span
                                        className={cn(
                                            'shrink-0 text-right text-sm font-semibold whitespace-nowrap tabular-nums',
                                            trip.distance === null &&
                                                'max-w-28 text-xs font-medium whitespace-normal text-amber-700',
                                        )}
                                    >
                                        {trip.distance === null
                                            ? t('trips.distance_unknown')
                                            : `${trip.distance.toFixed(1)} ${unitLabel}`}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="grid grid-cols-2 gap-2">
                    <Button variant="outline" className="h-11" asChild>
                        <a
                            href={
                                exportMethod({
                                    query: query({ period: 'month' }),
                                }).url
                            }
                        >
                            <Download /> {t('trips.export_month')}
                        </a>
                    </Button>
                    <Button variant="outline" className="h-11" asChild>
                        <a href={exportMethod({ query: query({}) }).url}>
                            <Download /> {t('trips.export_year')}
                        </a>
                    </Button>
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="top-4 translate-y-0 sm:top-[8vh] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editing
                                ? t('trips.edit_title')
                                : t('trips.add_title')}
                        </DialogTitle>
                        <DialogDescription className="sr-only">
                            {t('trips.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div
                            role="radiogroup"
                            aria-label={t('trips.fields.type')}
                            className="grid grid-cols-2 gap-2"
                        >
                            {types.map((type) => (
                                <button
                                    key={type.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.type === type.value}
                                    aria-pressed={form.data.type === type.value}
                                    className="da-chip min-h-11 rounded-xl px-3 text-sm font-medium"
                                    onClick={() =>
                                        form.setData('type', type.value)
                                    }
                                >
                                    {type.label}
                                </button>
                            ))}
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <FormField
                                id="trip-date"
                                label={t('trips.fields.date')}
                                error={form.errors.trip_date}
                            >
                                <Input
                                    id="trip-date"
                                    type="date"
                                    value={form.data.trip_date}
                                    max={today}
                                    onChange={(e) =>
                                        form.setData(
                                            'trip_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="trip-distance"
                                label={`${t('trips.fields.distance')}, ${unitLabel}`}
                                error={form.errors.distance}
                            >
                                <Input
                                    id="trip-distance"
                                    inputMode="decimal"
                                    value={form.data.distance}
                                    onChange={(e) =>
                                        form.setData('distance', e.target.value)
                                    }
                                />
                            </FormField>
                        </div>
                        <FormField
                            id="trip-from"
                            label={t('trips.fields.from')}
                            error={form.errors.from_address}
                        >
                            <Input
                                id="trip-from"
                                maxLength={255}
                                value={form.data.from_address}
                                onChange={(e) =>
                                    form.setData('from_address', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="trip-to"
                            label={t('trips.fields.to')}
                            error={form.errors.to_address}
                        >
                            <Input
                                id="trip-to"
                                maxLength={255}
                                value={form.data.to_address}
                                onChange={(e) =>
                                    form.setData('to_address', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="trip-purpose"
                            label={t('trips.fields.purpose')}
                            error={form.errors.purpose}
                        >
                            <Input
                                id="trip-purpose"
                                maxLength={255}
                                value={form.data.purpose}
                                onChange={(e) =>
                                    form.setData('purpose', e.target.value)
                                }
                            />
                        </FormField>
                        {editing?.job && (
                            <p className="text-sm">
                                <Link
                                    href={showJob(editing.job.id)}
                                    className="text-primary underline"
                                >
                                    {t('billing.job', {
                                        number: editing.job.number,
                                    })}
                                </Link>
                            </p>
                        )}
                        <div className="flex gap-2 pt-1">
                            {editing && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-12 text-destructive"
                                    onClick={remove}
                                >
                                    <Trash2 /> {t('trips.remove')}
                                </Button>
                            )}
                            <Button
                                type="submit"
                                className="h-12 flex-1"
                                disabled={form.processing}
                            >
                                {t('common.save')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Trips.layout = {
    breadcrumbs: [{ title: 'trips.title', href: index() }],
};
