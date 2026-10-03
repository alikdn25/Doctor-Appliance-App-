import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { TimezoneSelect } from '@/components/timezone-select';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/settings';
import {
    connect as connectProvider,
    disconnect as disconnectProvider,
} from '@/routes/payment-providers';
import type { Option } from '@/types';

const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;

type Day = { closed: boolean; open: string | null; close: string | null };

type CompanySettings = {
    name: string;
    country: string;
    timezone: string;
    currency: string;
    locale: string;
    prices_include_tax: boolean;
    online_tips: boolean;
    default_payment_terms: string;
    invoice_prefix: string;
    invoice_next_number: number;
    estimate_prefix: string;
    estimate_next_number: number;
    business_hours: Record<string, Day>;
    travel_buffer_minutes: number;
    estimate_valid_days: number | null;
    estimate_followup_days: number | null;
    technicians_can_delete_jobs: boolean;
    diagnostic_service_id: number | null;
    strict_arrival_reminder_minutes: number;
    closure_reasons: Record<string, string[]>;
    warranty_labor_value: number;
    warranty_labor_unit: string;
    warranty_parts_value: number;
    warranty_parts_unit: string;
    warranty_parts_threshold: number | null;
    warranty_parts_above_value: number | null;
    warranty_parts_above_unit: string | null;
    warranty_terms: string | null;
    technicians_see_costs: boolean;
    accepts_cash: boolean;
    payment_provider: string;
};


const REASON_OUTCOMES = [
    'customer_declined',
    'unable_to_repair',
    'no_charge',
    'cancelled',
] as const;

type Props = {
    company: CompanySettings & { id: number; vertical: string };
    timezones: Option[];
    currencies: Option[];
    countries: Option[];
    locales: Option[];
    paymentTerms: Option[];
    services: Option[];
    warrantyUnits: Option[];
    paymentProviders: Option[];
    providerConnections: ProviderConnection[];
};

type ProviderConnection = {
    key: string;
    label: string;
    connected: {
        account: string | null;
        location: string | null;
        currency: string | null;
    } | null;
};

export default function CompanySettingsPage({
    company,
    timezones,
    currencies,
    countries,
    locales,
    paymentTerms,
    services,
    warrantyUnits,
    paymentProviders,
    providerConnections,
}: Props) {
    const t = useTrans();
    const form = useForm<
        Omit<CompanySettings, 'closure_reasons'> & {
            closure_reasons_text: Record<string, string>;
        }
    >({
        name: company.name,
        country: company.country,
        timezone: company.timezone,
        currency: company.currency,
        locale: company.locale,
        prices_include_tax: company.prices_include_tax,
        online_tips: company.online_tips,
        default_payment_terms: company.default_payment_terms,
        invoice_prefix: company.invoice_prefix ?? '',
        invoice_next_number: company.invoice_next_number,
        estimate_prefix: company.estimate_prefix ?? '',
        estimate_next_number: company.estimate_next_number,
        business_hours: company.business_hours,
        travel_buffer_minutes: company.travel_buffer_minutes,
        estimate_valid_days: company.estimate_valid_days,
        estimate_followup_days: company.estimate_followup_days,
        technicians_can_delete_jobs: company.technicians_can_delete_jobs,
        diagnostic_service_id: company.diagnostic_service_id,
        strict_arrival_reminder_minutes:
            company.strict_arrival_reminder_minutes,
        warranty_labor_value: company.warranty_labor_value,
        warranty_labor_unit: company.warranty_labor_unit,
        warranty_parts_value: company.warranty_parts_value,
        warranty_parts_unit: company.warranty_parts_unit,
        warranty_parts_threshold: company.warranty_parts_threshold,
        warranty_parts_above_value: company.warranty_parts_above_value,
        warranty_parts_above_unit: company.warranty_parts_above_unit,
        warranty_terms: company.warranty_terms ?? '',
        technicians_see_costs: company.technicians_see_costs,
        accepts_cash: company.accepts_cash,
        // Edited as text, one reason per line; sent as lists.
        closure_reasons_text: Object.fromEntries(
            REASON_OUTCOMES.map((o) => [
                o,
                (company.closure_reasons[o] ?? []).join('\n'),
            ]),
        ) as Record<string, string>,
        payment_provider: company.payment_provider ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setDay = (day: string, patch: Partial<Day>) =>
        form.setData('business_hours', {
            ...form.data.business_hours,
            [day]: { ...form.data.business_hours[day], ...patch },
        });

    const select = (
        field:
            | 'country'
            | 'timezone'
            | 'currency'
            | 'locale'
            | 'default_payment_terms',
        options: Option[],
        hint?: string,
    ) => (
        <FormField
            id={field}
            label={t(`company.fields.${field}`)}
            hint={hint}
            error={errors[field]}
        >
            <NativeSelect
                id={field}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </NativeSelect>
        </FormField>
    );

    // A sample of the regional format: date, time and a number.
    let localeExample = '';

    try {
        localeExample = new Intl.DateTimeFormat(form.data.locale, {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(Date.UTC(2026, 9, 6, 16, 30)));
        localeExample += ` · ${new Intl.NumberFormat(form.data.locale, {
            style: 'currency',
            currency: form.data.currency,
        }).format(1234.5)}`;
    } catch {
        localeExample = '';
    }

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform(({ closure_reasons_text, ...data }) => ({
            ...data,
            payment_provider: data.payment_provider || null,
            closure_reasons: Object.fromEntries(
                Object.entries(closure_reasons_text).map(
                    ([o, text]: [string, string]) => [
                        o,
                        text
                            .split('\n')
                            .map((r: string) => r.trim())
                            .filter(Boolean),
                    ],
                ),
            ),
        }));
        form.put(update().url, { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('company.title')} />

            <form onSubmit={submit} className="max-w-2xl space-y-8 p-4">
                <PageHeader
                    title={t('company.title')}
                    description={t('company.description')}
                />

                <section className="grid gap-4">
                    <FormField
                        id="name"
                        label={t('company.fields.name')}
                        error={errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                    </FormField>

                    <p className="text-sm text-muted-foreground">
                        {t('company.fields.vertical')}: {company.vertical}
                    </p>
                </section>

                <section className="grid gap-4">
                    <h2 className="text-base font-medium">
                        {t('company.regional')}
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {select('country', countries)}
                        <FormField id="timezone" label={t('company.fields.timezone')} error={form.errors.timezone}>
                            <TimezoneSelect value={form.data.timezone} options={timezones} onChange={(value) => form.setData('timezone', value)} />
                        </FormField>
                        {select(
                            'locale',
                            locales,
                            localeExample &&
                                t('company.locale_example', {
                                    example: localeExample,
                                }),
                        )}
                        {select(
                            'currency',
                            currencies,
                            t('company.currency_hint'),
                        )}
                    </div>
                </section>

                <section className="grid gap-4">
                    <h2 className="text-base font-medium">
                        {t('company.billing')}
                    </h2>
                    {select(
                        'default_payment_terms',
                        paymentTerms,
                        t('company.payment_terms_hint'),
                    )}
                    <label className="flex min-h-10 items-start gap-2 text-sm">
                        <Checkbox
                            checked={form.data.prices_include_tax}
                            onCheckedChange={(c) =>
                                form.setData('prices_include_tax', c === true)
                            }
                        />
                        <span>
                            {t('company.fields.prices_include_tax')}
                            <span className="block text-xs text-muted-foreground">
                                {t('company.prices_include_tax_hint')}
                            </span>
                        </span>
                    </label>
                </section>

                <section className="grid gap-4">
                    <h2 className="text-base font-medium">
                        {t('company.numbering')}
                    </h2>
                    <div className="grid grid-cols-2 gap-4">
                        <FormField
                            id="invoice_prefix"
                            label={t('company.fields.invoice_prefix')}
                            error={errors.invoice_prefix}
                        >
                            <Input
                                id="invoice_prefix"
                                value={form.data.invoice_prefix}
                                onChange={(e) =>
                                    form.setData(
                                        'invoice_prefix',
                                        e.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="invoice_next_number"
                            label={t('company.fields.next_number')}
                            error={errors.invoice_next_number}
                        >
                            <Input
                                id="invoice_next_number"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                value={form.data.invoice_next_number}
                                onChange={(e) =>
                                    form.setData(
                                        'invoice_next_number',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="estimate_prefix"
                            label={t('company.fields.estimate_prefix')}
                            error={errors.estimate_prefix}
                        >
                            <Input
                                id="estimate_prefix"
                                value={form.data.estimate_prefix}
                                onChange={(e) =>
                                    form.setData(
                                        'estimate_prefix',
                                        e.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="estimate_next_number"
                            label={t('company.fields.next_number')}
                            error={errors.estimate_next_number}
                        >
                            <Input
                                id="estimate_next_number"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                value={form.data.estimate_next_number}
                                onChange={(e) =>
                                    form.setData(
                                        'estimate_next_number',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('company.numbering_example', {
                            example: `${form.data.invoice_prefix}${form.data.invoice_next_number}`,
                        })}
                    </p>
                    <FormField
                        id="estimate_followup_days"
                        label={t('company.fields.estimate_followup_days')}
                        hint={t('company.estimate_followup_days_hint')}
                        error={errors.estimate_followup_days}
                        className="sm:max-w-xs"
                    >
                        <Input
                            id="estimate_followup_days"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={90}
                            value={form.data.estimate_followup_days ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'estimate_followup_days',
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        id="estimate_valid_days"
                        label={t('company.fields.estimate_valid_days')}
                        hint={t('company.estimate_valid_days_hint')}
                        error={errors.estimate_valid_days}
                        className="sm:max-w-xs"
                    >
                        <Input
                            id="estimate_valid_days"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={365}
                            value={form.data.estimate_valid_days ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'estimate_valid_days',
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </FormField>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-medium">
                        {t('company.business_hours')}
                    </h2>
                    {WEEKDAYS.map((day) => {
                        const value = form.data.business_hours[day];

                        return (
                            <div
                                key={day}
                                className="grid gap-2 border-b pb-3 last:border-b-0"
                            >
                                <div className="flex items-center justify-between">
                                    <span className="text-sm font-medium">
                                        {t(`company.weekdays.${day}`)}
                                    </span>
                                    <label className="flex min-h-9 items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={value.closed}
                                            onCheckedChange={(checked) =>
                                                setDay(day, {
                                                    closed: checked === true,
                                                    open:
                                                        checked === true
                                                            ? null
                                                            : (value.open ??
                                                              '08:00'),
                                                    close:
                                                        checked === true
                                                            ? null
                                                            : (value.close ??
                                                              '17:00'),
                                                })
                                            }
                                        />
                                        {t('company.closed')}
                                    </label>
                                </div>
                                {!value.closed && (
                                    <div className="flex items-center gap-2 sm:max-w-sm">
                                        <Input
                                            type="time"
                                            aria-label={t('company.opens')}
                                            className="flex-1"
                                            value={value.open ?? ''}
                                            onChange={(e) =>
                                                setDay(day, {
                                                    open: e.target.value,
                                                })
                                            }
                                        />
                                        <span aria-hidden>–</span>
                                        <Input
                                            type="time"
                                            aria-label={t('company.closes')}
                                            className="flex-1"
                                            value={value.close ?? ''}
                                            onChange={(e) =>
                                                setDay(day, {
                                                    close: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                )}
                                <InputError
                                    message={
                                        errors[`business_hours.${day}.open`] ??
                                        errors[`business_hours.${day}.close`]
                                    }
                                />
                            </div>
                        );
                    })}
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-medium">
                        {t('company.dispatch')}
                    </h2>
                    <FormField
                        id="travel_buffer_minutes"
                        label={t('company.fields.travel_buffer_minutes')}
                        hint={t('company.travel_buffer_hint')}
                        error={errors.travel_buffer_minutes}
                        className="sm:max-w-xs"
                    >
                        <Input
                            id="travel_buffer_minutes"
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={240}
                            step={5}
                            value={form.data.travel_buffer_minutes}
                            onChange={(e) =>
                                form.setData(
                                    'travel_buffer_minutes',
                                    Number(e.target.value),
                                )
                            }
                        />
                    </FormField>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-medium">
                        {t('company.jobs_section')}
                    </h2>
                    <label className="flex min-h-10 items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.technicians_can_delete_jobs}
                            onCheckedChange={(c) =>
                                form.setData(
                                    'technicians_can_delete_jobs',
                                    c === true,
                                )
                            }
                        />
                        {t('company.fields.technicians_can_delete_jobs')}
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="diagnostic_service_id"
                            label={t('company.fields.diagnostic_service_id')}
                            hint={t('company.diagnostic_service_hint')}
                            error={errors.diagnostic_service_id}
                        >
                            <NativeSelect
                                id="diagnostic_service_id"
                                value={
                                    form.data.diagnostic_service_id === null
                                        ? ''
                                        : String(
                                              form.data.diagnostic_service_id,
                                          )
                                }
                                onChange={(e) =>
                                    form.setData(
                                        'diagnostic_service_id',
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                    )
                                }
                            >
                                <option value="">{t('company.none')}</option>
                                {services.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="strict_arrival_reminder_minutes"
                            label={t(
                                'company.fields.strict_arrival_reminder_minutes',
                            )}
                            error={errors.strict_arrival_reminder_minutes}
                        >
                            <Input
                                id="strict_arrival_reminder_minutes"
                                type="number"
                                inputMode="numeric"
                                min={5}
                                max={480}
                                step={5}
                                value={
                                    form.data.strict_arrival_reminder_minutes
                                }
                                onChange={(e) =>
                                    form.setData(
                                        'strict_arrival_reminder_minutes',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('company.closure_reasons_hint')}
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {REASON_OUTCOMES.map((outcome) => (
                            <FormField
                                key={outcome}
                                id={`reasons-${outcome}`}
                                label={t(`jobs.outcomes.${outcome}`)}
                                error={errors[`closure_reasons.${outcome}`]}
                            >
                                <Textarea
                                    id={`reasons-${outcome}`}
                                    rows={6}
                                    value={
                                        form.data.closure_reasons_text[outcome]
                                    }
                                    onChange={(e) =>
                                        form.setData('closure_reasons_text', {
                                            ...form.data.closure_reasons_text,
                                            [outcome]: e.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        ))}
                    </div>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-medium">
                        {t('company.warranty_section')}
                    </h2>
                    {(
                        [
                            [
                                'labor',
                                'warranty_labor_value',
                                'warranty_labor_unit',
                            ],
                            [
                                'parts',
                                'warranty_parts_value',
                                'warranty_parts_unit',
                            ],
                        ] as const
                    ).map(([key, value, unit]) => (
                        <div
                            key={key}
                            className="grid grid-cols-[1fr_5rem_8rem] items-end gap-2"
                        >
                            <span className="text-sm">
                                {t(`company.warranty_${key}`)}
                            </span>
                            <Input
                                aria-label={t(`company.warranty_${key}`)}
                                inputMode="numeric"
                                value={form.data[value]}
                                onChange={(e) =>
                                    form.setData(
                                        value,
                                        Number(
                                            e.target.value.replace(/\D/g, '') ||
                                                0,
                                        ),
                                    )
                                }
                            />
                            <NativeSelect
                                aria-label={t(`company.warranty_${key}`)}
                                value={form.data[unit]}
                                onChange={(e) =>
                                    form.setData(unit, e.target.value)
                                }
                            >
                                {warrantyUnits.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    ))}
                    <div className="grid grid-cols-[1fr_6rem] items-end gap-2">
                        <span className="text-sm">
                            {t('company.warranty_threshold')}
                        </span>
                        <Input
                            aria-label={t('company.warranty_threshold')}
                            inputMode="decimal"
                            value={form.data.warranty_parts_threshold ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'warranty_parts_threshold',
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </div>
                    <div className="grid grid-cols-[1fr_5rem_8rem] items-end gap-2">
                        <span className="text-sm">
                            {t('company.warranty_above')}
                        </span>
                        <Input
                            aria-label={t('company.warranty_above')}
                            inputMode="numeric"
                            value={form.data.warranty_parts_above_value ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'warranty_parts_above_value',
                                    e.target.value === ''
                                        ? null
                                        : Number(
                                              e.target.value.replace(/\D/g, ''),
                                          ),
                                )
                            }
                        />
                        <NativeSelect
                            aria-label={t('company.warranty_above')}
                            value={
                                form.data.warranty_parts_above_unit ?? 'months'
                            }
                            onChange={(e) =>
                                form.setData(
                                    'warranty_parts_above_unit',
                                    e.target.value,
                                )
                            }
                        >
                            {warrantyUnits.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('company.warranty_hint')}
                    </p>
                    <FormField
                        id="warranty_terms"
                        label={t('company.fields.warranty_terms')}
                        error={errors.warranty_terms}
                    >
                        <Textarea
                            id="warranty_terms"
                            rows={4}
                            value={form.data.warranty_terms ?? ''}
                            onChange={(e) =>
                                form.setData('warranty_terms', e.target.value)
                            }
                        />
                    </FormField>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-medium">
                        {t('company.costs_section')}
                    </h2>
                    <label className="flex min-h-10 items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.technicians_see_costs}
                            onCheckedChange={(c) =>
                                form.setData(
                                    'technicians_see_costs',
                                    c === true,
                                )
                            }
                        />
                        {t('company.fields.technicians_see_costs')}
                    </label>
                    <label className="flex min-h-10 items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.accepts_cash}
                            onCheckedChange={(c) =>
                                form.setData('accepts_cash', c === true)
                            }
                        />
                        <span>
                            {t('company.fields.accepts_cash')}
                            <span className="block text-xs text-muted-foreground">
                                {t('company.accepts_cash_hint')}
                            </span>
                        </span>
                    </label>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-medium">
                        {t('company.payments')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('payments.connect.hint')}
                    </p>
                    {providerConnections.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('payments.connect.none_available')}
                        </p>
                    )}
                    {providerConnections.map((provider) => (
                        <div
                            key={provider.key}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                        >
                            <div className="text-sm">
                                <p className="font-medium">{provider.label}</p>
                                {provider.connected ? (
                                    <>
                                        <p className="flex items-center gap-1 text-green-700 dark:text-green-400">
                                            <CheckCircle2 className="size-4" />
                                            {t(
                                                'payments.connect.connected_as',
                                                {
                                                    account:
                                                        provider.connected
                                                            .account ??
                                                        provider.label,
                                                },
                                            )}
                                        </p>
                                        {provider.connected.location && (
                                            <p className="text-muted-foreground">
                                                {t(
                                                    'payments.connect.location',
                                                    {
                                                        location:
                                                            provider.connected
                                                                .location,
                                                        currency:
                                                            provider.connected
                                                                .currency ?? '',
                                                    },
                                                )}
                                            </p>
                                        )}
                                    </>
                                ) : null}
                            </div>
                            {provider.connected ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        confirm(
                                            t(
                                                'payments.connect.confirm_disconnect',
                                                { provider: provider.label },
                                            ),
                                        ) &&
                                        router.delete(
                                            disconnectProvider(provider.key)
                                                .url,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {t('payments.connect.disconnect')}
                                </Button>
                            ) : (
                                <Button type="button" asChild>
                                    <a href={connectProvider(provider.key).url}>
                                        {t('payments.connect.connect', {
                                            provider: provider.label,
                                        })}
                                    </a>
                                </Button>
                            )}
                        </div>
                    ))}
                    <FormField
                        id="payment_provider"
                        label={t('company.fields.payment_provider')}
                        hint={t('company.payments_hint')}
                        error={errors.payment_provider}
                        className="sm:max-w-md"
                    >
                        <NativeSelect
                            id="payment_provider"
                            value={form.data.payment_provider}
                            onChange={(e) =>
                                form.setData('payment_provider', e.target.value)
                            }
                        >
                            <option value="">
                                {t('company.no_payment_provider')}
                            </option>
                            {paymentProviders.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <label className="flex min-h-10 items-start gap-2 text-sm">
                        <Checkbox
                            checked={form.data.online_tips}
                            onCheckedChange={(c) =>
                                form.setData('online_tips', c === true)
                            }
                        />
                        <span>
                            {t('company.fields.online_tips')}
                            <span className="block text-xs text-muted-foreground">
                                {t('company.online_tips_hint')}
                            </span>
                        </span>
                    </label>
                </section>

                <Button
                    type="submit"
                    className="w-full sm:w-auto"
                    disabled={form.processing}
                >
                    {t('common.save')}
                </Button>
            </form>
        </>
    );
}

CompanySettingsPage.layout = {
    breadcrumbs: [{ title: 'company.title', href: edit() }],
};
