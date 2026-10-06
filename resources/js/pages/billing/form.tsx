import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
import {
    computeTotals,
    currencyDecimals,
    currencySymbol,
    fromMinor,
    normalizeNumber,
    parseNumber,
    toNumber,
    useMoney,
} from '@/components/billing/money';
import { NumberHint } from '@/components/billing/number-hint';
import { depositFor } from '@/components/billing/estimate-approval';
import { LineEditor, newLine } from '@/components/billing/line-editor';
import type {
    Line,
    LineKind,
    LineSetup,
} from '@/components/billing/line-editor';
import type {
    BillingDocument,
    DocumentKind,
    DocumentRow,
    JobSummary,
    ServiceOption,
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

type FormData = {
    issued_on: string;
    valid_until: string;
    due_on: string;
    discount_type: '' | 'amount' | 'percent';
    discount_value: string;
    notes: string;
    tax_rate_ids: number[];
    items: Line[];
    deposit_type: '' | 'amount' | 'percent';
    deposit_value: string;
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
    defaultDueOn,
    services = [],
    paymentTerms,
    defaultValidUntil = null,
    canTakeDeposit = false,
    prefillItems = null,
    lineSetup,
    existingInvoices = [],
}: {
    kind: DocumentKind;
    document: BillingDocument | null;
    job: JobSummary;
    taxRates: TaxOption[];
    today: string;
    defaultDueOn?: string;
    paymentTerms?: string;
    services?: ServiceOption[];
    defaultValidUntil?: string | null;
    canTakeDeposit?: boolean;
    /** Lines to start a new document with (e.g. the diagnostic fee). */
    prefillItems?:
        | {
              description: string;
              unit_price: number | null;
              taxable: boolean;
              kind?: LineKind;
              quantity?: string;
              warranty_value?: number | null;
              warranty_unit?: string | null;
          }[]
        | null;
    lineSetup: LineSetup;
    /** Invoices this job already has (new invoice only). */
    existingInvoices?: DocumentRow[];
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    // A document keeps the currency and tax mode it was created with.
    const currency = document?.currency ?? auth.company?.currency ?? 'USD';
    const pricesIncludeTax =
        document?.prices_include_tax ??
        auth.company?.prices_include_tax ??
        false;
    const symbol = currencySymbol(currency, auth.company?.locale);
    const money = useMoney(currency);
    const group = kind === 'invoice' ? 'invoices' : 'estimates';

    const form = useForm<FormData>({
        issued_on: document?.issued_on ?? today,
        valid_until: document
            ? (document.valid_until ?? '')
            : (defaultValidUntil ?? ''),
        due_on: document?.due_on ?? (document ? '' : (defaultDueOn ?? today)),
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
            ? document.items.map((item) =>
                  newLine({
                      id: item.id,
                      costs_editable: item.costs_editable !== false,
                      description: item.description,
                      quantity: String(Number(item.quantity)),
                      unit_price: fromMinor(item.unit_price, currency),
                      taxable: item.taxable,
                      tax_rate_ids: item.tax_rate_ids ?? null,
                      optional: item.optional,
                      selected: item.selected,
                      kind: item.kind,
                      service_id: item.service_id,
                      part_number: item.part_number ?? '',
                      supplier: item.supplier ?? '',
                      unit: item.unit ?? '',
                      unit_cost:
                          item.unit_cost !== null
                              ? fromMinor(item.unit_cost, currency)
                              : '',
                      supplier_taxes: Object.fromEntries(
                          item.supplier_taxes.map((tax) => [
                              String(tax.tax_rate_id),
                              fromMinor(tax.amount, currency),
                          ]),
                      ),
                      bill_to_customer: item.bill_to_customer,
                      warranty_value:
                          item.warranty_value === null
                              ? ''
                              : String(item.warranty_value),
                      warranty_unit: item.warranty_unit ?? 'days',
                      price_touched: true,
                      warranty_touched: true,
                  }),
              )
            : prefillItems
              ? prefillItems.map((item) =>
                    newLine({
                        description: item.description,
                        unit_price:
                            item.unit_price !== null
                                ? fromMinor(item.unit_price, currency)
                                : '',
                        taxable: item.taxable,
                        price_touched: item.unit_price !== null,
                        ...(item.kind ? { kind: item.kind } : {}),
                        ...(item.quantity ? { quantity: item.quantity } : {}),
                        ...(item.warranty_value !== undefined &&
                        item.warranty_value !== null
                            ? {
                                  warranty_value: String(item.warranty_value),
                                  warranty_unit: item.warranty_unit ?? 'days',
                                  warranty_touched: true,
                              }
                            : {}),
                    }),
                )
              : [newLine()],
        deposit_type: document?.deposit_type ?? '',
        deposit_value:
            document?.deposit_type && document.deposit_value
                ? String(Number(document.deposit_value))
                : '',
    });
    const { data, errors } = form;
    const fieldErrors = errors as Record<string, string | undefined>;

    // Taxes as on the document (rates kept from when it was made), new ones at today's rate.
    const selectedTaxes = taxRates
        .filter((r) => data.tax_rate_ids.includes(r.id))
        .map(
            (r) =>
                document?.taxes.find((tax) => tax.tax_rate_id === r.id) ?? {
                    tax_rate_id: r.id,
                    name: r.name,
                    rate: r.rate,
                    compound: r.is_compound,
                },
        );
    const totals = computeTotals({
        items: data.items.map((line) => ({
            ...line,
            included:
                (!line.optional || line.selected) && line.bill_to_customer,
        })),
        discount_type: data.discount_type,
        discount_value: data.discount_value,
        taxes: selectedTaxes,
        currency,
        prices_include_tax: pricesIncludeTax,
    });

    const [addedKey, setAddedKey] = useState<number | null>(null);
    // A new empty line of that type: its price starts blank and is added to the total.
    const addLine = (lineKind: LineKind) => {
        const line = newLine({ kind: lineKind });
        setAddedKey(line.key);
        form.setData((d) => {
            // The untouched first line of a new document is reused instead of leaving an empty line behind.
            const [only] = d.items;
            const blank =
                d.items.length === 1 &&
                only.description.trim() === '' &&
                only.unit_price.trim() === '';

            return { ...d, items: blank ? [line] : [...d.items, line] };
        });
    };

    // Updates always start from the latest lines: two quick changes (a typed price and the default
    // warranty that follows it) must not overwrite each other.
    const setLine = (key: number, patch: Partial<Line>) =>
        form.setData((d) => ({
            ...d,
            items: d.items.map((line) =>
                line.key === key ? { ...line, ...patch } : line,
            ),
        }));

    const toggleTax = (id: number, on: boolean) =>
        form.setData(
            'tax_rate_ids',
            on
                ? [...data.tax_rate_ids, id]
                : data.tax_rate_ids.filter((x) => x !== id),
        );

    const fmt = (value: number) =>
        money(Math.round(value * 10 ** currencyDecimals(currency)));

    // Checked before sending, next to the Save button: numbers that cannot be read and a discount over 100%.
    const clientErrors = (): Record<string, string> => {
        const found: Record<string, string> = {};
        const invalid = t('billing.number.invalid', { example: '150.50' });
        data.items.forEach((line, i) => {
            if (parseNumber(line.quantity).normalized === null) {
                found[`items.${i}.quantity`] = invalid;
            }

            if (parseNumber(line.unit_price).normalized === null) {
                found[`items.${i}.unit_price`] = invalid;
            }

            if (parseNumber(line.unit_cost).normalized === null) {
                found[`items.${i}.unit_cost`] = invalid;
            }
        });

        if (data.discount_type) {
            if (parseNumber(data.discount_value).normalized === null) {
                found.discount_value = invalid;
            } else if (
                data.discount_type === 'percent' &&
                toNumber(data.discount_value) > 100
            ) {
                found.discount_value = t('billing.percent_over_100');
            } else if (
                data.discount_type === 'amount' &&
                totals.subtotal > 0 &&
                toNumber(data.discount_value) *
                    10 ** currencyDecimals(currency) >
                    totals.subtotal
            ) {
                found.discount_value = t('billing.discount_over_subtotal');
            }
        }

        if (kind === 'estimate' && data.deposit_type) {
            if (parseNumber(data.deposit_value).normalized === null) {
                found.deposit_value = invalid;
            } else if (
                data.deposit_type === 'percent' &&
                toNumber(data.deposit_value) > 100
            ) {
                found.deposit_value = t('billing.percent_over_100');
            }
        }

        return found;
    };
    const blocking = clientErrors();
    const hasErrors =
        Object.keys(blocking).length > 0 || Object.keys(errors).length > 0;

    // A double tap fires twice before `processing` re-renders: the ref stops the second document.
    const sending = useRef(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (form.processing || sending.current) {
            return;
        }

        form.clearErrors();

        if (Object.keys(blocking).length > 0) {
            form.setError(blocking as Partial<Record<keyof FormData, string>>);

            return;
        }

        form.transform((d) => ({
            ...d,
            valid_until:
                kind === 'estimate' ? d.valid_until || null : undefined,
            due_on: kind === 'invoice' ? d.due_on || null : undefined,
            discount_type: d.discount_type || null,
            discount_value: d.discount_type
                ? normalizeNumber(d.discount_value) || 0
                : null,
            items: d.items.map((line) => ({
                id: line.id,
                description: line.description,
                quantity: normalizeNumber(line.quantity),
                unit_price: normalizeNumber(line.unit_price),
                taxable: line.taxable,
                tax_rate_ids:
                    line.tax_rate_ids === null
                        ? null
                        : line.tax_rate_ids.filter((id) =>
                              d.tax_rate_ids.includes(id),
                          ),
                kind: line.kind,
                service_id: line.service_id,
                part_number: line.part_number || null,
                unit:
                    line.kind === 'material' ? line.unit.trim() || null : null,
                bill_to_customer: line.bill_to_customer,
                warranty_value:
                    line.warranty_value === ''
                        ? null
                        : Number(line.warranty_value),
                warranty_unit: line.warranty_unit,
                // A service has no purchase price: values typed while the line was a part are not sent.
                ...(lineSetup.costs_visible && line.costs_editable
                    ? line.kind === 'service'
                        ? {
                              supplier: null,
                              unit_cost: null,
                              supplier_taxes: [],
                          }
                        : {
                              supplier: line.supplier || null,
                              unit_cost:
                                  normalizeNumber(line.unit_cost) || null,
                              supplier_taxes: Object.entries(
                                  line.supplier_taxes,
                              )
                                  .filter(([, amount]) => amount.trim() !== '')
                                  .map(([id, amount]) => ({
                                      tax_rate_id: Number(id),
                                      amount: normalizeNumber(amount),
                                  })),
                          }
                    : {}),
                ...(kind === 'estimate'
                    ? { optional: line.optional, selected: line.selected }
                    : {}),
            })),
            deposit_type:
                kind === 'estimate' ? d.deposit_type || null : undefined,
            deposit_value:
                kind === 'estimate' && d.deposit_type
                    ? normalizeNumber(d.deposit_value) || 0
                    : undefined,
        }));

        sending.current = true;
        const options = {
            onFinish: () => {
                sending.current = false;
            },
        };

        if (document) {
            form.put(
                (kind === 'invoice'
                    ? updateInvoice(document.id)
                    : updateEstimate(document.id)
                ).url,
                options,
            );
        } else {
            form.post(
                (kind === 'invoice'
                    ? storeInvoice(job.id)
                    : storeEstimate(job.id)
                ).url,
                options,
            );
        }
    };

    const title = document
        ? t(`${group}.edit_title`, { number: document.number })
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
                    back={back}
                    description={[
                        t('billing.job', { number: job.number }),
                        job.customer,
                        job.address,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />

                {!document && existingInvoices.length > 0 && (
                    <p
                        className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
                        role="status"
                    >
                        {t('invoices.already_invoiced', {
                            list: existingInvoices
                                .map(
                                    (invoice) =>
                                        `${invoice.number} (${invoice.status_label}, ${money(invoice.total, invoice.currency)})`,
                                )
                                .join(', '),
                        })}
                    </p>
                )}

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
                            hint={
                                paymentTerms &&
                                t('invoices.terms_hint', {
                                    terms: paymentTerms,
                                })
                            }
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
                            <LineEditor
                                key={line.key}
                                line={line}
                                index={i}
                                currency={currency}
                                symbol={symbol}
                                total={totals.itemTotals[i] ?? 0}
                                setup={lineSetup}
                                services={services}
                                taxRates={selectedTaxes}
                                estimate={kind === 'estimate'}
                                canRemove={data.items.length > 1}
                                autoFocus={line.key === addedKey}
                                errors={fieldErrors}
                                money={money}
                                onChange={(patch) => {
                                    const touched = Object.keys(patch).map(
                                        (field) => `items.${i}.${field}`,
                                    );

                                    if (
                                        touched.some((field) => field in errors)
                                    ) {
                                        form.clearErrors(
                                            ...(touched as (keyof FormData)[]),
                                        );
                                    }

                                    setLine(line.key, patch);
                                }}
                                onRemove={() =>
                                    form.setData((d) => ({
                                        ...d,
                                        items: d.items.filter(
                                            (item) => item.key !== line.key,
                                        ),
                                    }))
                                }
                            />
                        ))}
                    </ul>
                    {/* Labor, parts and materials are separate lines that add up: one button per type. */}
                    <div className="grid grid-cols-3 gap-2">
                        {(['service', 'part', 'material'] as const).map(
                            (lineKind) => (
                                <Button
                                    key={lineKind}
                                    type="button"
                                    variant="outline"
                                    className="h-11 px-2"
                                    onClick={() => addLine(lineKind)}
                                >
                                    <Plus /> {t(`billing.add_kind.${lineKind}`)}
                                </Button>
                            ),
                        )}
                    </div>
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
                                onChange={(e) => {
                                    form.clearErrors('discount_value');
                                    form.setData(
                                        'discount_type',
                                        e.target
                                            .value as FormData['discount_type'],
                                    );
                                }}
                            >
                                <option value="">
                                    {t('billing.discount_types.none')}
                                </option>
                                <option value="amount">
                                    {t('billing.discount_types.amount', {
                                        symbol,
                                    })}
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
                                    onChange={(e) => {
                                        form.clearErrors('discount_value');
                                        form.setData(
                                            'discount_value',
                                            e.target.value,
                                        );
                                    }}
                                />
                            )}
                        </div>
                        {data.discount_type === 'amount' && (
                            <NumberHint
                                text={data.discount_value}
                                format={fmt}
                            />
                        )}
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

                {kind === 'estimate' && (
                    <FormField
                        id="deposit_type"
                        label={t('estimates.fields.deposit')}
                        hint={
                            canTakeDeposit
                                ? t('estimates.deposit_hint')
                                : t('estimates.deposit_no_provider')
                        }
                        error={errors.deposit_value ?? errors.deposit_type}
                    >
                        <div className="flex gap-2">
                            <NativeSelect
                                id="deposit_type"
                                value={data.deposit_type}
                                onChange={(e) =>
                                    form.setData(
                                        'deposit_type',
                                        e.target
                                            .value as FormData['deposit_type'],
                                    )
                                }
                            >
                                <option value="">
                                    {t('estimates.deposit_types.none')}
                                </option>
                                <option value="percent">
                                    {t('estimates.deposit_types.percent')}
                                </option>
                                <option value="amount">
                                    {t('estimates.deposit_types.amount', {
                                        symbol,
                                    })}
                                </option>
                            </NativeSelect>
                            {data.deposit_type && (
                                <Input
                                    aria-label={t('estimates.fields.deposit')}
                                    inputMode="decimal"
                                    className="w-28"
                                    value={data.deposit_value}
                                    onChange={(e) => {
                                        form.clearErrors('deposit_value');
                                        form.setData(
                                            'deposit_value',
                                            e.target.value,
                                        );
                                    }}
                                />
                            )}
                        </div>
                    </FormField>
                )}

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
                    {pricesIncludeTax && (
                        <p className="text-right text-xs text-muted-foreground">
                            {t('billing.prices_include_tax')}
                        </p>
                    )}
                    {kind === 'estimate' && data.deposit_type && (
                        <div className="flex justify-between">
                            <dt>{t('estimates.deposit')}</dt>
                            <dd className="tabular-nums">
                                {money(
                                    depositFor(totals.total, {
                                        currency,
                                        deposit_type: data.deposit_type,
                                        deposit_value: data.deposit_value,
                                    }),
                                )}
                            </dd>
                        </div>
                    )}
                </dl>

                <div className="da-pinned fixed inset-x-0 bottom-0 z-30 flex flex-wrap gap-2 border-t bg-background/95 p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shadow-lg backdrop-blur md:static md:border-0 md:bg-transparent md:p-0 md:shadow-none">
                    {hasErrors && (
                        <p
                            className="w-full text-sm font-medium text-destructive"
                            role="alert"
                        >
                            {Object.values(blocking)[0] ??
                                Object.values(fieldErrors).find(Boolean) ??
                                t('billing.fix_errors')}
                        </p>
                    )}
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
                        disabled={
                            form.processing || Object.keys(blocking).length > 0
                        }
                    >
                        {t('common.save')} · {money(totals.total)}
                    </Button>
                </div>
            </form>
        </>
    );
}
