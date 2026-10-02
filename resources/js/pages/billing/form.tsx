import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { computeTotals, toDollars, useMoney } from '@/components/billing/money';
import type {
    BillingDocument,
    DocumentKind,
    JobSummary,
    TaxOption,
} from '@/components/billing/types';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import {
    show as showEstimate,
    store as storeEstimate,
    update as updateEstimate,
} from '@/routes/estimates';
import {
    show as showInvoice,
    store as storeInvoice,
    update as updateInvoice,
} from '@/routes/invoices';
import { show as showJob } from '@/routes/jobs';

type Line = {
    key: number;
    description: string;
    quantity: string;
    unit_price: string;
    taxable: boolean;
};

// Keys for React lists only; never sent to the server.
let lineKey = 0;

const newLine = (): Line => ({
    key: ++lineKey,
    description: '',
    quantity: '1',
    unit_price: '',
    taxable: true,
});

type FormData = {
    issued_on: string;
    valid_until: string;
    due_on: string;
    discount_type: '' | 'amount' | 'percent';
    discount_value: string;
    notes: string;
    tax_rate_ids: number[];
    items: Line[];
};

/**
 * Estimate or invoice form. One line per card so it works with one hand on a phone;
 * totals update as you type (the server recalculates them on save).
 */
export default function BillingForm({
    kind,
    document,
    job,
    taxRates,
    today,
}: {
    kind: DocumentKind;
    document: BillingDocument | null;
    job: JobSummary;
    taxRates: TaxOption[];
    today: string;
}) {
    const t = useTrans();
    const money = useMoney();
    const group = kind === 'invoice' ? 'invoices' : 'estimates';

    const form = useForm<FormData>({
        issued_on: document?.issued_on ?? today,
        valid_until: document?.valid_until ?? '',
        due_on: document?.due_on ?? (document ? '' : today),
        discount_type: document?.discount_type ?? '',
        discount_value:
            document?.discount_type && document.discount_value
                ? String(Number(document.discount_value))
                : '',
        notes: document?.notes ?? '',
        tax_rate_ids: document
            ? document.taxes
                  .map((tax) => tax.tax_rate_id)
                  .filter((id): id is number => id !== null)
            : taxRates.filter((r) => r.is_default).map((r) => r.id),
        items: document
            ? document.items.map((item) => ({
                  key: ++lineKey,
                  description: item.description,
                  quantity: String(Number(item.quantity)),
                  unit_price: toDollars(item.unit_price),
                  taxable: item.taxable,
              }))
            : [newLine()],
    });
    const { data, errors } = form;
    const fieldErrors = errors as Record<string, string | undefined>;

    // Taxes as on the document (rates kept from when it was made), new ones at today's rate.
    const selectedTaxes = taxRates
        .filter((r) => data.tax_rate_ids.includes(r.id))
        .map(
            (r) => document?.taxes.find((tax) => tax.tax_rate_id === r.id) ?? r,
        );
    const totals = computeTotals({
        items: data.items,
        discount_type: data.discount_type,
        discount_value: data.discount_value,
        taxes: selectedTaxes,
    });

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData(
            'items',
            data.items.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const toggleTax = (id: number, on: boolean) =>
        form.setData(
            'tax_rate_ids',
            on
                ? [...data.tax_rate_ids, id]
                : data.tax_rate_ids.filter((x) => x !== id),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            valid_until:
                kind === 'estimate' ? d.valid_until || null : undefined,
            due_on: kind === 'invoice' ? d.due_on || null : undefined,
            discount_type: d.discount_type || null,
            discount_value: d.discount_type ? d.discount_value || 0 : null,
            items: d.items.map((line) => ({
                description: line.description,
                quantity: line.quantity,
                unit_price: line.unit_price.replace(/[$,\s]/g, ''),
                taxable: line.taxable,
            })),
        }));

        if (document) {
            form.put(
                (kind === 'invoice'
                    ? updateInvoice(document.id)
                    : updateEstimate(document.id)
                ).url,
            );
        } else {
            form.post(
                (kind === 'invoice'
                    ? storeInvoice(job.id)
                    : storeEstimate(job.id)
                ).url,
            );
        }
    };

    const title = document
        ? t(`${group}.number`, { number: document.number })
        : t(`${group}.add`);
    const back = document
        ? kind === 'invoice'
            ? showInvoice(document.id)
            : showEstimate(document.id)
        : showJob(job.id);

    return (
        <>
            <Head title={title} />

            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4 pb-28">
                <PageHeader
                    title={title}
                    description={[
                        t('billing.job', { number: job.number }),
                        job.customer,
                        job.address,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />

                <section className="grid grid-cols-2 gap-3">
                    <FormField
                        id="issued_on"
                        label={t('billing.fields.issued_on')}
                        error={errors.issued_on}
                    >
                        <Input
                            id="issued_on"
                            type="date"
                            value={data.issued_on}
                            onChange={(e) =>
                                form.setData('issued_on', e.target.value)
                            }
                        />
                    </FormField>
                    {kind === 'invoice' ? (
                        <FormField
                            id="due_on"
                            label={t('invoices.fields.due_on')}
                            error={errors.due_on}
                        >
                            <Input
                                id="due_on"
                                type="date"
                                value={data.due_on}
                                onChange={(e) =>
                                    form.setData('due_on', e.target.value)
                                }
                            />
                        </FormField>
                    ) : (
                        <FormField
                            id="valid_until"
                            label={t('estimates.fields.valid_until')}
                            error={errors.valid_until}
                        >
                            <Input
                                id="valid_until"
                                type="date"
                                value={data.valid_until}
                                onChange={(e) =>
                                    form.setData('valid_until', e.target.value)
                                }
                            />
                        </FormField>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-medium">
                        {t('billing.items')}
                    </h2>
                    <InputError message={errors.items} />
                    <ul className="space-y-3">
                        {data.items.map((line, i) => (
                            <li
                                key={line.key}
                                className="space-y-3 rounded-lg border p-3"
                            >
                                <div className="flex items-start gap-2">
                                    <div className="flex-1">
                                        <Textarea
                                            aria-label={t(
                                                'billing.fields.description',
                                            )}
                                            placeholder={t(
                                                'billing.fields.description',
                                            )}
                                            rows={2}
                                            maxLength={500}
                                            value={line.description}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    description: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                fieldErrors[
                                                    `items.${i}.description`
                                                ]
                                            }
                                        />
                                    </div>
                                    {data.items.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label={t(
                                                'billing.remove_item',
                                            )}
                                            onClick={() =>
                                                form.setData(
                                                    'items',
                                                    data.items.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </div>
                                <div className="grid grid-cols-[5rem_1fr] gap-2 sm:grid-cols-[5rem_10rem_1fr]">
                                    <div>
                                        <Input
                                            aria-label={t(
                                                'billing.fields.quantity',
                                            )}
                                            inputMode="decimal"
                                            value={line.quantity}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    quantity: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                fieldErrors[
                                                    `items.${i}.quantity`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div>
                                        <div className="relative">
                                            <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                                                $
                                            </span>
                                            <Input
                                                aria-label={t(
                                                    'billing.fields.unit_price',
                                                )}
                                                placeholder="0.00"
                                                inputMode="decimal"
                                                className="pl-6"
                                                value={line.unit_price}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        unit_price:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <InputError
                                            message={
                                                fieldErrors[
                                                    `items.${i}.unit_price`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="col-span-2 flex items-center justify-between gap-3 sm:col-span-1">
                                        <label className="flex min-h-10 items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={line.taxable}
                                                onCheckedChange={(c) =>
                                                    setLine(i, {
                                                        taxable: c === true,
                                                    })
                                                }
                                            />
                                            {t('billing.taxable')}
                                        </label>
                                        <span className="font-medium tabular-nums">
                                            {money(totals.itemTotals[i] ?? 0)}
                                        </span>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11 w-full"
                        onClick={() =>
                            form.setData('items', [...data.items, newLine()])
                        }
                    >
                        <Plus /> {t('billing.add_item')}
                    </Button>
                </section>

                <section className="grid gap-3 sm:grid-cols-2">
                    <FormField
                        id="discount_type"
                        label={t('billing.fields.discount')}
                        error={errors.discount_value ?? errors.discount_type}
                    >
                        <div className="flex gap-2">
                            <NativeSelect
                                id="discount_type"
                                value={data.discount_type}
                                onChange={(e) =>
                                    form.setData(
                                        'discount_type',
                                        e.target
                                            .value as FormData['discount_type'],
                                    )
                                }
                            >
                                <option value="">
                                    {t('billing.discount_types.none')}
                                </option>
                                <option value="amount">
                                    {t('billing.discount_types.amount')}
                                </option>
                                <option value="percent">
                                    {t('billing.discount_types.percent')}
                                </option>
                            </NativeSelect>
                            {data.discount_type && (
                                <Input
                                    aria-label={t('billing.fields.discount')}
                                    inputMode="decimal"
                                    className="w-28"
                                    value={data.discount_value}
                                    onChange={(e) =>
                                        form.setData(
                                            'discount_value',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </div>
                    </FormField>

                    <div className="grid gap-2">
                        <span className="text-sm font-medium">
                            {t('billing.taxes')}
                        </span>
                        {taxRates.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                {t('billing.no_taxes')}
                            </p>
                        ) : (
                            <div className="flex flex-wrap gap-x-4">
                                {taxRates.map((rate) => (
                                    <label
                                        key={rate.id}
                                        className="flex min-h-10 items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={data.tax_rate_ids.includes(
                                                rate.id,
                                            )}
                                            onCheckedChange={(c) =>
                                                toggleTax(rate.id, c === true)
                                            }
                                        />
                                        {t('billing.tax_line', {
                                            name: rate.name,
                                            rate: rate.rate,
                                        })}
                                    </label>
                                ))}
                            </div>
                        )}
                        <InputError message={errors.tax_rate_ids} />
                    </div>
                </section>

                <FormField
                    id="notes"
                    label={t('billing.fields.notes')}
                    hint={t('billing.notes_hint')}
                    error={errors.notes}
                >
                    <Textarea
                        id="notes"
                        rows={3}
                        maxLength={5000}
                        value={data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                </FormField>

                <dl className="ml-auto max-w-xs space-y-1 text-sm">
                    <div className="flex justify-between">
                        <dt>{t('billing.subtotal')}</dt>
                        <dd className="tabular-nums">
                            {money(totals.subtotal)}
                        </dd>
                    </div>
                    {totals.discount > 0 && (
                        <div className="flex justify-between">
                            <dt>{t('billing.discount')}</dt>
                            <dd className="tabular-nums">
                                −{money(totals.discount)}
                            </dd>
                        </div>
                    )}
                    {totals.taxes.map((tax) => (
                        <div key={tax.name} className="flex justify-between">
                            <dt>
                                {t('billing.tax_line', {
                                    name: tax.name,
                                    rate: tax.rate,
                                })}
                            </dt>
                            <dd className="tabular-nums">
                                {money(tax.amount)}
                            </dd>
                        </div>
                    ))}
                    <div className="flex justify-between border-t pt-1 text-base font-semibold">
                        <dt>{t('billing.total')}</dt>
                        <dd className="tabular-nums">{money(totals.total)}</dd>
                    </div>
                </dl>

                <div className="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t bg-background/95 p-3 shadow-lg backdrop-blur md:static md:border-0 md:bg-transparent md:p-0 md:shadow-none">
                    <Button
                        type="button"
                        variant="outline"
                        className="h-12 md:h-9"
                        asChild
                    >
                        <Link href={back}>{t('common.cancel')}</Link>
                    </Button>
                    <Button
                        type="submit"
                        className="h-12 flex-1 md:h-9 md:flex-none"
                        disabled={form.processing}
                    >
                        {t('common.save')} · {money(totals.total)}
                    </Button>
                </div>
            </form>
        </>
    );
}
