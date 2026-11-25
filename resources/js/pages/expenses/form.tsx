import { Head, Link, useForm } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import {
    currencyDecimals,
    computeTotals,
    fromMinor,
    toMinor,
    useMoney,
} from '@/components/billing/money';
import { FilePicker } from '@/components/file-picker';
import { FormField } from '@/components/form-field';
import { ScreenHeader } from '@/components/screen-header';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import type { TaxOption } from '@/components/billing/types';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { compressImage } from '@/lib/image';
import { takeReceipt } from '@/lib/receipt-scan';
import {
    index,
    readReceipt as readReceiptRoute,
    store,
    update,
} from '@/routes/expenses';
import type { ExpenseCategory, ExpenseJob, ExpenseRow } from './types';

type ExpenseForm = {
    _method: 'post' | 'put';
    category_id: string;
    service_job_id: string;
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
    recentCategoryIds = [],
    jobs = [],
    jobId = null,
    withTaxes = true,
}: {
    expense: ExpenseRow | null;
    categories: ExpenseCategory[];
    /** The jobs it can be for (the person's recent ones). */
    jobs?: ExpenseJob[];
    /** Opened from a job page: that job is chosen. */
    jobId?: number | null;
    currency: string;
    today: string;
    taxRates: TaxOption[];
    recentCategoryIds?: number[];
    /** False for My expenses (technicians): only the amount paid, no taxes. */
    withTaxes?: boolean;
}) {
    const t = useTrans();
    const recent = recentCategoryIds
        .map((id) => categories.find((c) => c.id === id && c.is_active))
        .filter((c): c is ExpenseCategory => c !== undefined);
    const money = useMoney(currency);
    const title = t(expense ? 'expenses.edit' : 'expenses.add');
    const form = useForm<ExpenseForm>({
        _method: expense ? 'put' : 'post',
        category_id: expense ? String(expense.category_id) : '',
        service_job_id: String(expense?.service_job_id ?? jobId ?? ''),
        new_category: '',
        spent_on: expense?.spent_on ?? today,
        description: expense?.description ?? '',
        merchant: expense?.merchant ?? '',
        // My expenses shows what was paid (taxes the office split off included).
        amount: expense
            ? fromMinor(
                  withTaxes
                      ? expense.amount
                      : expense.amount + expense.tax_amount,
                  currency,
              )
            : '',
        tax_amount: expense ? fromMinor(expense.tax_amount, currency) : '0',
        use_named_taxes: withTaxes && (!expense || expense.taxes !== null),
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
    // "Read receipt" (owner's task of November 18): the photo goes to the AI, the form is filled and highlighted, the
    // photo becomes the receipt; nothing is saved until Save.
    const [scanning, setScanning] = useState(false);
    const [scanNote, setScanNote] = useState<string | null>(null);
    const [scanned, setScanned] = useState<string[]>([]);
    const cameraInput = useRef<HTMLInputElement>(null);
    const lit = (field: string) =>
        scanned.includes(field)
            ? 'bg-amber-50 ring-2 ring-amber-400'
            : undefined;
    const readReceipt = async (file: File) => {
        setScanning(true);
        setScanNote(t('automation.receipt.reading'));
        try {
            const { blob, filename } = await compressImage(file);
            const photo = new File([blob], filename, { type: blob.type });
            form.setData('receipt', photo);
            const body = new FormData();
            body.append('photo', photo);
            const response = await fetch(readReceiptRoute().url, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });
            const result = (await response.json()) as {
                fields?: Record<string, unknown>;
                message?: string;
            };
            if (!response.ok || !result.fields) {
                setScanNote(result.message ?? t('automation.receipt.failed'));

                return;
            }
            const f = result.fields;
            const touched: string[] = [];
            const patch: Partial<ExpenseForm> = {};
            const text = (key: keyof ExpenseForm) => {
                if (typeof f[key] === 'string' && f[key] !== '') {
                    (patch as Record<string, unknown>)[key] = f[key];
                    touched.push(key);
                }
            };
            text('merchant');
            text('spent_on');
            text('description');
            text('amount');
            if (typeof f.category_id === 'number') {
                patch.category_id = String(f.category_id);
                patch.new_category = '';
                touched.push('category_id');
            } else if (typeof f.new_category === 'string') {
                patch.category_id = '';
                patch.new_category = f.new_category;
                touched.push('category_id');
            }
            if (withTaxes) {
                patch.use_named_taxes = f.use_named_taxes === true;
                patch.tax_rate_ids = (f.tax_rate_ids as number[]) ?? [];
                patch.tax_amounts =
                    (f.tax_amounts as Record<string, string>) ?? {};
                patch.tax_amount =
                    typeof f.tax_amount === 'string' ? f.tax_amount : '0';
                touched.push('taxes');
            }
            form.setData((data) => ({ ...data, ...patch, receipt: photo }));
            setScanned(touched);
            setScanNote(t('automation.receipt.filled'));
        } catch {
            setScanNote(t('automation.receipt.failed'));
        } finally {
            setScanning(false);
        }
    };

    // A photo taken with the camera on the Expenses list.
    useEffect(() => {
        const file = expense ? null : takeReceipt();
        if (file) void readReceipt(file);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

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
    const taxTotal = !withTaxes
        ? 0
        : form.data.use_named_taxes
          ? Object.values(amounts).reduce(
                (sum, amount) => sum + toMinor(amount, currency),
                0,
            )
          : toMinor(form.data.tax_amount, currency);
    const total = toMinor(form.data.amount, currency) + taxTotal;
    const fieldErrors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) =>
            withTaxes
                ? {
                      ...data,
                      tax_amounts: data.use_named_taxes ? amounts : {},
                      tax_amount: data.use_named_taxes
                          ? fromMinor(taxTotal, currency)
                          : data.tax_amount,
                  }
                : {
                      ...data,
                      use_named_taxes: false,
                      tax_rate_ids: [],
                      tax_amounts: {},
                      tax_amount: '0',
                  },
        );
        // POST with method override also supports receipt replacement on an existing expense.
        form.post(expense ? update(expense.id).url : store().url, {
            forceFormData: true,
        });
    }

    const receiptField = (
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
                        {expense.receipt_name ?? t('expenses.view_receipt')}
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
    );

    return (
        <>
            <Head title={title} />
            <ScreenHeader title={title} back={index()} />
            <div className="mx-auto w-full max-w-3xl p-4 sm:p-6">
                <form onSubmit={submit} className="space-y-5">
                    {!expense && (
                        <div
                            className="space-y-2 rounded-2xl border border-violet-200 bg-violet-50/60 p-3"
                            data-test="read-receipt"
                        >
                            <Button
                                type="button"
                                size="lg"
                                variant="outline"
                                className="w-full bg-white"
                                loading={scanning}
                                onClick={() => cameraInput.current?.click()}
                            >
                                <Camera /> {t('automation.receipt.scan')}
                            </Button>
                            <input
                                ref={cameraInput}
                                type="file"
                                accept="image/*"
                                capture="environment"
                                className="hidden"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    event.target.value = '';
                                    if (file) void readReceipt(file);
                                }}
                            />
                            <p
                                role="status"
                                className="text-sm text-violet-800"
                            >
                                {scanNote ?? t('automation.receipt.scan_hint')}
                            </p>
                        </div>
                    )}
                    {/* Receipt first: snap it, then fill in the rest (UX-REDESIGN §11.2). */}
                    {!expense && receiptField}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="spent_on"
                            label={t('expenses.fields.date')}
                            error={form.errors.spent_on}
                        >
                            <Input
                                id="spent_on"
                                className={lit('spent_on')}
                                type="date"
                                value={form.data.spent_on}
                                onChange={(event) =>
                                    form.setData('spent_on', event.target.value)
                                }
                            />
                        </FormField>
                        {recent.length > 0 && (
                            <div
                                className="flex flex-wrap gap-2 sm:col-span-2"
                                role="group"
                                aria-label={t('expenses.recent_categories')}
                            >
                                {recent.map((category) => (
                                    <button
                                        key={category.id}
                                        type="button"
                                        aria-pressed={
                                            form.data.category_id ===
                                            String(category.id)
                                        }
                                        className="da-chip da-press inline-flex h-10 items-center rounded-full px-3.5 text-sm font-medium"
                                        onClick={() => {
                                            form.setData(
                                                'category_id',
                                                String(category.id),
                                            );
                                            form.setData('new_category', '');
                                        }}
                                    >
                                        {category.name}
                                    </button>
                                ))}
                            </div>
                        )}
                        <FormField
                            id="category_id"
                            label={t('expenses.fields.category')}
                            error={form.errors.category_id}
                        >
                            <NativeSelect
                                id="category_id"
                                className={lit('category_id')}
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
                                className={lit('description')}
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
                                className={lit('merchant')}
                                maxLength={150}
                                value={form.data.merchant}
                                onChange={(event) =>
                                    form.setData('merchant', event.target.value)
                                }
                            />
                        </FormField>
                        {/* Optional: an expense for a job counts in that job's Costs. */}
                        <FormField
                            id="service_job_id"
                            label={t('expenses.fields.job')}
                            hint={t('expenses.fields.job_hint')}
                            error={fieldErrors.service_job_id}
                            className="sm:col-span-2"
                        >
                            <NativeSelect
                                id="service_job_id"
                                value={form.data.service_job_id}
                                onChange={(event) =>
                                    form.setData(
                                        'service_job_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    {t('expenses.fields.job_none')}
                                </option>
                                {jobs.map((job) => (
                                    <option key={job.id} value={job.id}>
                                        {[`#${job.number}`, job.customer]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="amount"
                            label={`${t(withTaxes ? 'expenses.fields.price' : 'expenses.fields.amount_paid')} (${currency})`}
                            hint={
                                withTaxes ? t('expenses.price_hint') : undefined
                            }
                            error={form.errors.amount}
                        >
                            <Input
                                id="amount"
                                className={lit('amount')}
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step={step}
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        {withTaxes && !form.data.use_named_taxes && (
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
                    {withTaxes && (
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
                                            (entry) =>
                                                entry.tax_rate_id === tax.id,
                                        );
                                        const selected =
                                            form.data.tax_rate_ids.includes(
                                                tax.id,
                                            );
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
                                                                          (
                                                                              id,
                                                                          ) =>
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
                                                            value={
                                                                amounts[
                                                                    String(
                                                                        tax.id,
                                                                    )
                                                                ] ?? '0'
                                                            }
                                                            onChange={(event) =>
                                                                form.setData(
                                                                    'tax_amounts',
                                                                    {
                                                                        ...form
                                                                            .data
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
                    )}
                    {expense && receiptField}
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
                    <StickyActionBar>
                        <Button
                            variant="outline"
                            className="min-h-12 !flex-none md:min-h-11"
                            asChild
                        >
                            <Link href={index()}>{t('common.cancel')}</Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="min-h-12 md:min-h-11"
                            data-test="save-expense"
                        >
                            {t('common.save')} · {t('expenses.fields.total')}{' '}
                            {money(total)}
                        </Button>
                    </StickyActionBar>
                </form>
            </div>
        </>
    );
}
