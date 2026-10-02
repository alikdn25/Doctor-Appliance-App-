import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { currencyDecimals, fromMinor, toMinor, useMoney } from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
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
    notes: string;
    receipt: File | null;
};

export default function BusinessExpenseForm({
    expense, categories, currency, today,
}: {
    expense: ExpenseRow | null;
    categories: ExpenseCategory[];
    currency: string;
    today: string;
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
        notes: expense?.notes ?? '',
        receipt: null,
    });
    const decimals = currencyDecimals(currency);
    const step = decimals === 0 ? '1' : (1 / 10 ** decimals).toFixed(decimals);
    const total = toMinor(form.data.amount, currency) + toMinor(form.data.tax_amount, currency);

    function submit(event: FormEvent) {
        event.preventDefault();
        // POST with method override also supports receipt replacement on an existing expense.
        form.post(expense ? update(expense.id).url : store().url, { forceFormData: true });
    }

    return (
        <>
            <Head title={title} />
            <div className="mx-auto w-full max-w-3xl p-4">
                <PageHeader title={title} description={t('expenses.description')} />
                <form onSubmit={submit} className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField id="spent_on" label={t('expenses.fields.date')} error={form.errors.spent_on}>
                            <Input id="spent_on" type="date" required value={form.data.spent_on} onChange={(event) => form.setData('spent_on', event.target.value)} />
                        </FormField>
                        <FormField id="category_id" label={t('expenses.fields.category')} error={form.errors.category_id}>
                            <NativeSelect id="category_id" value={form.data.category_id} onChange={(event) => {
                                form.setData('category_id', event.target.value);
                                if (event.target.value) form.setData('new_category', '');
                            }}>
                                <option value="">{t('expenses.add_category')}</option>
                                {categories.map((category) => <option key={category.id} value={category.id}>{category.name}{!category.is_active ? ` (${t('expenses.archived')})` : ''}</option>)}
                            </NativeSelect>
                        </FormField>
                        {!form.data.category_id && (
                            <FormField id="new_category" label={t('expenses.fields.name')} error={form.errors.new_category} className="sm:col-span-2">
                                <Input id="new_category" required maxLength={80} value={form.data.new_category} onChange={(event) => form.setData('new_category', event.target.value)} />
                            </FormField>
                        )}
                        <FormField id="description" label={t('expenses.fields.description')} error={form.errors.description} className="sm:col-span-2">
                            <Input id="description" required maxLength={255} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                        </FormField>
                        <FormField id="merchant" label={t('expenses.fields.merchant')} error={form.errors.merchant} className="sm:col-span-2">
                            <Input id="merchant" maxLength={150} value={form.data.merchant} onChange={(event) => form.setData('merchant', event.target.value)} />
                        </FormField>
                        <FormField id="amount" label={`${t('expenses.fields.price')} (${currency})`} error={form.errors.amount}>
                            <Input id="amount" type="number" inputMode="decimal" min="0" step={step} required value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} />
                        </FormField>
                        <FormField id="tax_amount" label={`${t('expenses.fields.tax')} (${currency})`} error={form.errors.tax_amount}>
                            <Input id="tax_amount" type="number" inputMode="decimal" min="0" step={step} required value={form.data.tax_amount} onChange={(event) => form.setData('tax_amount', event.target.value)} />
                        </FormField>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-muted/40 p-4">
                        <p className="text-sm text-muted-foreground">{t('expenses.price_hint')}</p>
                        <p className="font-semibold tabular-nums">{t('expenses.fields.total')}: {money(total)}</p>
                    </div>
                    <FormField id="receipt" label={t('expenses.receipt_optional')} error={form.errors.receipt} hint={t('expenses.receipt_hint')}>
                        <Input id="receipt" type="file" accept="image/jpeg,image/png,image/webp,image/heic,application/pdf" onChange={(event) => form.setData('receipt', event.target.files?.[0] ?? null)} />
                        {expense?.receipt_url && (
                            <div className="space-y-1 text-sm">
                                <a href={expense.receipt_url} target="_blank" rel="noopener noreferrer" className="underline">{expense.receipt_name ?? t('expenses.view_receipt')}</a>
                                <p className="text-xs text-muted-foreground">{t('expenses.receipt_keep')}</p>
                            </div>
                        )}
                        {form.progress && <progress aria-label={t('expenses.receipt_optional')} value={form.progress.percentage} max={100} className="w-full" />}
                    </FormField>
                    <FormField id="notes" label={t('expenses.fields.notes')} error={form.errors.notes}>
                        <Textarea id="notes" maxLength={5000} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />
                    </FormField>
                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing} className="min-h-11 flex-1 sm:flex-none">{t('common.save')}</Button>
                        <Button variant="outline" className="min-h-11" asChild><Link href={index()}>{t('common.cancel')}</Link></Button>
                    </div>
                </form>
            </div>
        </>
    );
}
