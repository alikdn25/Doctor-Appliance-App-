import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/settings';
import type { Option } from '@/types';

const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;

type Day = { closed: boolean; open: string | null; close: string | null };

type CompanySettings = {
    name: string;
    timezone: string;
    currency: string;
    invoice_prefix: string;
    invoice_next_number: number;
    estimate_prefix: string;
    estimate_next_number: number;
    business_hours: Record<string, Day>;
    travel_buffer_minutes: number;
    payment_provider: string;
};

type Props = {
    company: CompanySettings & { id: number };
    timezones: string[];
    currencies: string[];
    paymentProviders: Option[];
};

export default function CompanySettingsPage({
    company,
    timezones,
    currencies,
    paymentProviders,
}: Props) {
    const t = useTrans();
    const form = useForm<CompanySettings>({
        name: company.name,
        timezone: company.timezone,
        currency: company.currency,
        invoice_prefix: company.invoice_prefix ?? '',
        invoice_next_number: company.invoice_next_number,
        estimate_prefix: company.estimate_prefix ?? '',
        estimate_next_number: company.estimate_next_number,
        business_hours: company.business_hours,
        travel_buffer_minutes: company.travel_buffer_minutes,
        payment_provider: company.payment_provider ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setDay = (day: string, patch: Partial<Day>) =>
        form.setData('business_hours', {
            ...form.data.business_hours,
            [day]: { ...form.data.business_hours[day], ...patch },
        });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            payment_provider: data.payment_provider || null,
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

                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="timezone"
                            label={t('company.fields.timezone')}
                            error={errors.timezone}
                        >
                            <NativeSelect
                                id="timezone"
                                value={form.data.timezone}
                                onChange={(e) =>
                                    form.setData('timezone', e.target.value)
                                }
                            >
                                {timezones.map((tz) => (
                                    <option key={tz} value={tz}>
                                        {tz}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>

                        <FormField
                            id="currency"
                            label={t('company.fields.currency')}
                            error={errors.currency}
                        >
                            <NativeSelect
                                id="currency"
                                value={form.data.currency}
                                onChange={(e) =>
                                    form.setData('currency', e.target.value)
                                }
                            >
                                {currencies.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
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
                        {t('company.payments')}
                    </h2>
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
