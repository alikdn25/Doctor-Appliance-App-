import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    currencyDecimals,
    computeTotals,
    fromMinor,
    toMinor,
    useMoney,
} from '@/components/billing/money';
import { FilePicker } from '@/components/file-picker';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import type { TaxOption } from '@/components/billing/types';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { index, store, update } from '@/routes/expenses';
import type { ExpenseCategory, ExpenseRow } from './types';

type ExpenseForm = {
    _method: 'post' | 'put';
    category_id: string;
    new_category: string;
    spent_on: string;
    description: string;
    merchant: string;
    amount: string;
    tax_amount: string;
    use_named_taxes: boolean;
    tax_rate_ids: number[];
    tax_amounts: Record<string, string>;
    notes: string;
    receipt: File | null;
};

export default function BusinessExpenseForm({
    expense,
    categories,
    currency,
    today,
    taxRates,
}: {
    expense: ExpenseRow | null;
    categories: ExpenseCategory[];
    currency: string;
    today: string;
    taxRates: TaxOption[];
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const title = t(expense ? 'expenses.edit' : 'expenses.add');
    const form = useForm<ExpenseForm>({
        _method: expense ? 'put' : 'post',
        category_id: expense ? String(expense.category_id) : '',
        new_category: '',
        spent_on: expense?.spent_on ?? today,
        description: expense?.description ?? '',
        merchant: expense?.merchant ?? '',
        amount: expense ? fromMinor(expense.amount, currency) : '',
        tax_amount: expense ? fromMinor(expense.tax_amount, currency) : '0',
        use_named_taxes: !expense || expense.taxes !== null,
        tax_rate_ids:
            expense?.taxes?.map((tax) => tax.tax_rate_id) ??
            taxRates.filter((tax) => tax.is_default).map((tax) => tax.id),
        tax_amounts: Object.fromEntries(
            (expense?.taxes ?? []).map((tax) => [
                String(tax.tax_rate_id),
                fromMinor(tax.amount, currency),
            ]),
        ),
        notes: expense?.notes ?? '',
        receipt: null,
    });
    const decimals = currencyDecimals(currency);
    const step = decimals === 0 ? '1' : (1 / 10 ** decimals).toFixed(decimals);
    const selectedTaxes = taxRates
        .filter((tax) => form.data.tax_rate_ids.includes(tax.id))
        .map(
            (tax) =>
                expense?.taxes?.find(
                    (saved) => saved.tax_rate_id === tax.id,
                ) ?? {
                    tax_rate_id: tax.id,
                    name: tax.name,
                    rate: tax.rate,
                    compound: tax.is_compound,
                },
        );
    const calculated = computeTotals({
        items: [{ quantity: '1', unit_price: form.data.amount, taxable: true }],
        discount_type: '',
        discount_value: '',
        taxes: selectedTaxes,
        currency,
        prices_include_tax: false,
    });
    const amounts = Object.fromEntries(
        selectedTaxes.map((tax, i) => [
            String(tax.tax_rate_id),
            form.data.tax_amounts[String(tax.tax_rate_id)] ??
                fromMinor(calculated.taxes[i].amount, currency),
        ]),
    );
    const taxTotal = form.data.use_named_taxes
        ? Object.values(amounts).reduce(
              (sum, amount) => sum + toMinor(amount, currency),
              0,
          )
        : toMinor(form.data.tax_amount, currency);
    const total = toMinor(form.data.amount, currency) + taxTotal;
    const fieldErrors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            tax_amounts: data.use_named_taxes ? amounts : {},
            tax_amount: data.use_named_taxes
                ? fromMinor(taxTotal, currency)
                : data.tax_amount,
        }));
        // POST with method override also supports receipt replacement on an existing expense.
        form.post(expense ? update(expense.id).url : store().url, {
            forceFormData: true,
        });
    }

    return (
        <>
            <Head title={title} />
            <div className="mx-auto w-full max-w-3xl p-4">
                <PageHeader
                    title={title}
                    description={t('expenses.description')}
                />
                <form onSubmit={submit} className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="spent_on"
                            label={t('expenses.fields.date')}
                            error={form.errors.spent_on}
                        >
                            <Input
                                id="spent_on"
                                type="date"
                                required
                                value={form.data.spent_on}
                                onChange={(event) =>
                                    form.setData('spent_on', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="category_id"
                            label={t('expenses.fields.category')}
                            error={form.errors.category_id}
                        >
                            <NativeSelect
                                id="category_id"
                                value={form.data.category_id}
                                onChange={(event) => {
                                    form.setData(
                                        'category_id',
                                        event.target.value,
                                    );
                                    if (event.target.value)
                                        form.setData('new_category', '');
                                }}
                            >
                                <option value="">
                                    {t('expenses.add_category')}
                                </option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                        {!category.is_active
                                            ? ` (${t('expenses.archived')})`
                                            : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        {!form.data.category_id && (
                            <FormField
                                id="new_category"
                                label={t('expenses.fields.name')}
                                error={form.errors.new_category}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="new_category"
                                    required
                                    maxLength={80}
                                    value={form.data.new_category}
                                    onChange={(event) =>
                                        form.setData(
                                            'new_category',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                        <FormField
                            id="description"
                            label={t('expenses.fields.description')}
                            error={form.errors.description}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="description"
                                required
                                maxLength={255}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="merchant"
                            label={t('expenses.fields.merchant')}
                            error={form.errors.merchant}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="merchant"
                                maxLength={150}
                                value={form.data.merchant}
                                onChange={(event) =>
                                    form.setData('merchant', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="amount"
                            label={`${t('expenses.fields.price')} (${currency})`}
                            error={form.errors.amount}
                        >
                            <Input
                                id="amount"
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step={step}
                                required
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        {!form.data.use_named_taxes && (
                            <FormField
                                id="tax_amount"
                                label={`${t('expenses.fields.tax')} (${currency})`}
                                error={form.errors.tax_amount}
                            >
                                <Input
                                    id="tax_amount"
                                    type="number"
                                    inputMode="decimal"
                                    min="0"
                                    step={step}
                                    required
                                    value={form.data.tax_amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'tax_amount',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                    </div>
                    <fieldset className="space-y-3 rounded-lg border p-4">
                        <legend className="px-1 text-sm font-medium">
                            {t('expenses.named_taxes')}
                        </legend>
                        {!form.data.use_named_taxes && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    form.setData('use_named_taxes', true)
                                }
                            >
                                {t('expenses.allocate_taxes')}
                            </Button>
                        )}
                        {form.data.use_named_taxes && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {t('expenses.tax_hint')}
                                </p>
                                {taxRates.length === 0 && (
                                    <p className="text-sm">
                                        {t('expenses.no_taxes')}
                                    </p>
                                )}
                                {taxRates.map((tax) => {
                                    const saved = expense?.taxes?.find(
                                        (entry) => entry.tax_rate_id === tax.id,
                                    );
                                    const selected =
                                        form.data.tax_rate_ids.includes(tax.id);
                                    return (
                                        <div
                                            key={tax.id}
                                            className="grid items-center gap-2 sm:grid-cols-2"
                                        >
                                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={selected}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        form.setData(
                                                            'tax_rate_ids',
                                                            checked === true
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .tax_rate_ids,
                                                                      tax.id,
                                                                  ]
                                                                : form.data.tax_rate_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          tax.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {saved?.name ?? tax.name} (
                                                {saved?.rate ?? tax.rate}%)
                                            </label>
                                            {selected && (
                                                <FormField
                                                    id={`tax-${tax.id}`}
                                                    label={`${t('expenses.actual_tax')} (${currency})`}
                                                    error={
                                                        fieldErrors[
                                                            `tax_amounts.${tax.id}`
                                                        ]
                                                    }
                                                >
                                                    <Input
                                                        id={`tax-${tax.id}`}
                                                        type="number"
                                                        min="0"
                                                        step={step}
                                                        inputMode="decimal"
                                                        required
                                                        value={
                                                            amounts[
                                                                String(tax.id)
                                                            ] ?? '0'
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'tax_amounts',
                                                                {
                                                                    ...form.data
                                                                        .tax_amounts,
                                                                    [String(
                                                                        tax.id,
                                                                    )]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </FormField>
                                            )}
                                        </div>
                                    );
                                })}
                                {fieldErrors.tax_rate_ids && (
                                    <p className="text-sm text-destructive">
                                        {fieldErrors.tax_rate_ids}
                                    </p>
                                )}
                                {Object.entries(fieldErrors)
                                    .filter(([key]) =>
                                        key.startsWith('tax_rate_ids.'),
                                    )
                                    .map(([key, error]) => (
                                        <p
                                            key={key}
                                            className="text-sm text-destructive"
                                        >
                                            {error}
                                        </p>
                                    ))}
                            </>
                        )}
                        <p className="text-right text-sm font-medium tabular-nums">
                            {t('expenses.fields.tax')}: {money(taxTotal)}
                        </p>
                    </fieldset>
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-muted/40 p-4">
                        <p className="text-sm text-muted-foreground">
                            {t('expenses.price_hint')}
                        </p>
                        <p className="font-semibold tabular-nums">
                            {t('expenses.fields.total')}: {money(total)}
                        </p>
                    </div>
                    <FormField
                        id="receipt"
                        label={t('expenses.receipt_optional')}
                        error={form.errors.receipt}
                        hint={t('expenses.receipt_hint')}
                    >
                        <FilePicker
                            id="receipt"
                            accept="image/jpeg,image/png,image/webp,image/heic,application/pdf"
                            file={form.data.receipt}
                            label={t('expenses.add_receipt')}
                            onChange={(file) => form.setData('receipt', file)}
                        />
                        {expense?.receipt_url && (
                            <div className="space-y-1 text-sm">
                                <a
                                    href={expense.receipt_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline"
                                >
                                    {expense.receipt_name ??
                                        t('expenses.view_receipt')}
                                </a>
                                <p className="text-xs text-muted-foreground">
                                    {t('expenses.receipt_keep')}
                                </p>
                            </div>
                        )}
                        {form.progress && (
                            <progress
                                aria-label={t('expenses.receipt_optional')}
                                value={form.progress.percentage}
                                max={100}
                                className="w-full"
                            />
                        )}
                    </FormField>
                    <FormField
                        id="notes"
                        label={t('expenses.fields.notes')}
                        error={form.errors.notes}
                    >
                        <Textarea
                            id="notes"
                            maxLength={5000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                    <div className="flex gap-3">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="min-h-11 flex-1 sm:flex-none"
                        >
                            {t('common.save')}
                        </Button>
                        <Button variant="outline" className="min-h-11" asChild>
                            <Link href={index()}>{t('common.cancel')}</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
