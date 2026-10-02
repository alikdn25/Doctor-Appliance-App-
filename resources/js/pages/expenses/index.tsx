import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Download,
    FileImage,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useMoney } from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    store as storeCategory,
    update as updateCategory,
} from '@/routes/expense-categories';
import { create, destroy, edit, download, index } from '@/routes/expenses';
import type { ExpenseCategory, ExpenseRow } from './types';

type Filters = { from: string; to: string; category: string; search: string; employee: string };

export default function BusinessExpenses({
    expenses,
    categories,
    filters,
    currency,
    employees,
    employeeTotals,
    companyView,
}: {
    expenses: Paginated<ExpenseRow>;
    categories: ExpenseCategory[];
    filters: Filters;
    currency: string;
    employees: { id: number; name: string }[];
    employeeTotals: { id: number | null; name: string; currency: string; price: number; tax: number; total: number }[];
    companyView: boolean;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const time = useCompanyTime();
    const { errors } = usePage().props;
    const [query, setQuery] = useState(filters);
    const [categoryOpen, setCategoryOpen] = useState(false);
    const [editingCategory, setEditingCategory] =
        useState<ExpenseCategory | null>(null);
    const categoryForm = useForm({ name: '', is_active: true });
    const filterParams = (next: Filters) =>
        Object.fromEntries(
            Object.entries(next).filter(([, value]) => value !== ''),
        );

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get(index().url, filterParams(query), {
            preserveState: true,
            replace: true,
        });
    }

    function openCategory(category: ExpenseCategory | null) {
        setEditingCategory(category);
        categoryForm.clearErrors();
        categoryForm.setData({
            name: category?.name ?? '',
            is_active: category?.is_active ?? true,
        });
        setCategoryOpen(true);
    }

    function saveCategory(event: FormEvent) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setCategoryOpen(false),
        };
        if (editingCategory)
            categoryForm.put(updateCategory(editingCategory.id).url, options);
        else categoryForm.post(storeCategory().url, options);
    }

    function remove(expense: ExpenseRow) {
        if (confirm(t('expenses.confirm_remove')))
            router.delete(destroy(expense.id).url, { preserveScroll: true });
    }

    return (
        <>
            <Head title={t('expenses.title')} />
            <div className="space-y-6 p-4">
                <PageHeader
                    title={t('expenses.title')}
                    description={t('expenses.description')}
                    actions={
                        <>
                            <Button
                                variant="outline"
                                className="min-h-11"
                                asChild
                            >
                                <a
                                    href={
                                        download({
                                            query: filterParams(filters),
                                        }).url
                                    }
                                >
                                    <Download />
                                    {t('expenses.export')}
                                </a>
                            </Button>
                            <Button className="min-h-11" asChild>
                                <Link href={create()}>
                                    <Plus />
                                    {t('expenses.add')}
                                </Link>
                            </Button>
                        </>
                    }
                />
                <p className="text-sm text-muted-foreground">
                    {t('expenses.own_hint')}
                </p>
                <form
                    onSubmit={apply}
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1fr_2fr_auto]"
                >
                    <FormField
                        id="from"
                        label={t('expenses.fields.from')}
                        error={errors.from}
                    >
                        <Input
                            id="from"
                            type="date"
                            required
                            value={query.from}
                            onChange={(event) =>
                                setQuery({ ...query, from: event.target.value })
                            }
                        />
                    </FormField>
                    <FormField
                        id="to"
                        label={t('expenses.fields.to')}
                        error={errors.to}
                    >
                        <Input
                            id="to"
                            type="date"
                            required
                            min={query.from}
                            value={query.to}
                            onChange={(event) =>
                                setQuery({ ...query, to: event.target.value })
                            }
                        />
                    </FormField>
                    <FormField
                        id="category"
                        label={t('expenses.fields.category')}
                    >
                        <NativeSelect
                            id="category"
                            value={query.category}
                            onChange={(event) =>
                                setQuery({
                                    ...query,
                                    category: event.target.value,
                                })
                            }
                        >
                            <option value="">
                                {t('expenses.all_categories')}
                            </option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    {companyView && <FormField id="employee" label={t('expenses.fields.employee')} error={errors.employee}>
                        <NativeSelect id="employee" value={query.employee} onChange={(event) => setQuery({ ...query, employee: event.target.value })}>
                            <option value="">{t('expenses.all_employees')}</option>
                            {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
                        </NativeSelect>
                    </FormField>}
                    <FormField id="search" label={t('common.search')}>
                        <Input
                            id="search"
                            type="search"
                            placeholder={t('expenses.search')}
                            value={query.search}
                            onChange={(event) =>
                                setQuery({
                                    ...query,
                                    search: event.target.value,
                                })
                            }
                        />
                    </FormField>
                    <div className="flex items-end gap-2">
                        <Button
                            type="submit"
                            variant="outline"
                            className="min-h-11"
                            aria-label={t('common.search')}
                        >
                            <Search />
                        </Button>
                        <Button variant="ghost" className="min-h-11" asChild>
                            <Link href={index()}>{t('expenses.clear')}</Link>
                        </Button>
                    </div>
                </form>
                {companyView && <section className="space-y-3">
                    <h2 className="font-semibold">{t('expenses.by_employee')}</h2>
                    <p className="text-sm text-muted-foreground">{t('expenses.employee_hint')}</p>
                    {employeeTotals.length === 0 ? <p className="text-sm text-muted-foreground">{t('expenses.empty')}</p> :
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[500px] text-sm">
                                <thead className="bg-muted/50"><tr>
                                    <th className="px-3 py-3 text-left">{t('expenses.fields.employee')}</th>
                                    {(['price', 'tax', 'total'] as const).map((field) => <th key={field} className="px-3 py-3 text-right">{t(`expenses.fields.${field}`)}</th>)}
                                </tr></thead>
                                <tbody className="divide-y">{employeeTotals.map((employee) => <tr key={`${employee.id}-${employee.currency}`}>
                                    <td className="px-3 py-3">{employee.id ? <Link className="font-medium underline" href={index({ query: filterParams({ ...filters, employee: String(employee.id) }) })}>{employee.name}</Link> : employee.name}<span className="ml-2 text-xs text-muted-foreground">{employee.currency}</span></td>
                                    {(['price', 'tax', 'total'] as const).map((field) => <td key={field} className="px-3 py-3 text-right whitespace-nowrap tabular-nums">{money(employee[field], employee.currency)}</td>)}
                                </tr>)}</tbody>
                            </table>
                        </div>
                    }
                </section>}
                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="font-semibold">
                            {t('expenses.categories')}
                        </h2>
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => openCategory(null)}
                        >
                            <Plus />
                            {t('expenses.add_category')}
                        </Button>
                    </div>
                    {categories.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('expenses.no_categories')}
                        </p>
                    ) : (
                        <>
                            <div className="grid gap-3 sm:hidden">
                                {categories.map((category) => (
                                    <div
                                        key={category.id}
                                        className="rounded-lg border p-3"
                                    >
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <Link
                                                href={index({
                                                    query: filterParams({
                                                        ...filters,
                                                        category: String(
                                                            category.id,
                                                        ),
                                                    }),
                                                })}
                                                className="font-medium underline"
                                            >
                                                {category.name}
                                                {!category.is_active && (
                                                    <span className="ml-2 text-xs">
                                                        {t('expenses.archived')}
                                                    </span>
                                                )}
                                            </Link>
                                            {category.can_update && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-11"
                                                    aria-label={`${t('common.edit')}: ${category.name}`}
                                                    onClick={() =>
                                                        openCategory(category)
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                            )}
                                        </div>
                                        {(category.totals?.length
                                            ? category.totals
                                            : [
                                                  {
                                                      currency,
                                                      price: 0,
                                                      tax: 0,
                                                      total: 0,
                                                  },
                                              ]
                                        ).map((total) => (
                                            <div
                                                key={total.currency}
                                                className="grid grid-cols-3 gap-2 py-1 text-xs"
                                            >
                                                {(
                                                    [
                                                        'price',
                                                        'tax',
                                                        'total',
                                                    ] as const
                                                ).map((field) => (
                                                    <div key={field}>
                                                        <span className="text-muted-foreground">
                                                            {t(
                                                                `expenses.fields.${field}`,
                                                            )}
                                                        </span>
                                                        <p className="mt-1 font-medium break-words tabular-nums">
                                                            {money(
                                                                total[field],
                                                                total.currency,
                                                            )}
                                                        </p>
                                                    </div>
                                                ))}
                                            </div>
                                        ))}
                                    </div>
                                ))}
                            </div>
                            <div className="hidden overflow-x-auto rounded-lg border sm:block">
                                <table className="w-full min-w-[560px] text-sm">
                                    <thead className="bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-3 text-left">
                                                {t('expenses.fields.category')}
                                            </th>
                                            {(
                                                [
                                                    'price',
                                                    'tax',
                                                    'total',
                                                ] as const
                                            ).map((field) => (
                                                <th
                                                    key={field}
                                                    className="px-4 py-3 text-right"
                                                >
                                                    {t(
                                                        `expenses.fields.${field}`,
                                                    )}
                                                </th>
                                            ))}
                                            <th className="w-14">
                                                <span className="sr-only">
                                                    {t('common.edit')}
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {categories.map((category) => {
                                            const totals = category.totals
                                                ?.length
                                                ? category.totals
                                                : [
                                                      {
                                                          currency,
                                                          price: 0,
                                                          tax: 0,
                                                          total: 0,
                                                      },
                                                  ];
                                            return totals.map(
                                                (total, position) => (
                                                    <tr
                                                        key={`${category.id}-${total.currency}`}
                                                    >
                                                        <td className="px-4 py-3">
                                                            <Link
                                                                href={index({
                                                                    query: filterParams(
                                                                        {
                                                                            ...filters,
                                                                            category:
                                                                                String(
                                                                                    category.id,
                                                                                ),
                                                                        },
                                                                    ),
                                                                })}
                                                                className="font-medium hover:underline"
                                                            >
                                                                {category.name}
                                                            </Link>
                                                            {!category.is_active && (
                                                                <span className="ml-2 text-xs text-muted-foreground">
                                                                    {t(
                                                                        'expenses.archived',
                                                                    )}
                                                                </span>
                                                            )}
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                {total.currency}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                                            {money(
                                                                total.price,
                                                                total.currency,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                                            {money(
                                                                total.tax,
                                                                total.currency,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">
                                                            {money(
                                                                total.total,
                                                                total.currency,
                                                            )}
                                                        </td>
                                                        <td className="px-2">
                                                            {category.can_update &&
                                                                position ===
                                                                    0 && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="size-11"
                                                                        aria-label={`${t('common.edit')}: ${category.name}`}
                                                                        onClick={() =>
                                                                            openCategory(
                                                                                category,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Pencil />
                                                                    </Button>
                                                                )}
                                                        </td>
                                                    </tr>
                                                ),
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>
                {expenses.data.length === 0 ? (
                    <p className="rounded-lg border p-6 text-center text-sm text-muted-foreground">
                        {t('expenses.empty')}
                    </p>
                ) : (
                    <>
                        <div className="grid gap-3 lg:hidden">
                            {expenses.data.map((expense) => (
                                <div
                                    key={expense.id}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <Link
                                                href={edit(expense.id)}
                                                className="font-medium underline"
                                            >
                                                {expense.description}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {expense.category} ·{' '}
                                                {time.dateOnly(
                                                    expense.spent_on,
                                                )}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {[
                                                    expense.merchant,
                                                    expense.creator,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0">
                                            {expense.receipt_url && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-11"
                                                    asChild
                                                >
                                                    <a
                                                        href={
                                                            expense.receipt_url
                                                        }
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        aria-label={t(
                                                            'expenses.view_receipt',
                                                        )}
                                                    >
                                                        <FileImage />
                                                    </a>
                                                </Button>
                                            )}
                                            {expense.can_delete && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-11"
                                                    aria-label={t(
                                                        'common.remove',
                                                    )}
                                                    onClick={() =>
                                                        remove(expense)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2 text-xs">
                                        {(
                                            ['price', 'tax', 'total'] as const
                                        ).map((field) => (
                                            <div key={field}>
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        `expenses.fields.${field}`,
                                                    )}
                                                </span>
                                                <p className="mt-1 font-medium break-words tabular-nums">
                                                    {money(
                                                        field === 'price'
                                                            ? expense.amount
                                                            : field === 'tax'
                                                              ? expense.tax_amount
                                                              : expense.total,
                                                        expense.currency,
                                                    )}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="hidden overflow-x-auto rounded-lg border lg:block">
                            <table className="w-full min-w-[850px] text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        {(
                                            [
                                                'date',
                                                'description',
                                                'category',
                                                'price',
                                                'tax',
                                                'total',
                                            ] as const
                                        ).map((field) => (
                                            <th
                                                key={field}
                                                className={`px-4 py-3 ${['price', 'tax', 'total'].includes(field) ? 'text-right' : 'text-left'}`}
                                            >
                                                {t(`expenses.fields.${field}`)}
                                            </th>
                                        ))}
                                        <th>
                                            <span className="sr-only">
                                                {t('common.edit')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {expenses.data.map((expense) => (
                                        <tr key={expense.id}>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {time.dateOnly(
                                                    expense.spent_on,
                                                )}
                                            </td>
                                            <td className="max-w-sm px-4 py-3">
                                                <Link
                                                    href={edit(expense.id)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {expense.description}
                                                </Link>
                                                <p className="text-xs text-muted-foreground">
                                                    {[
                                                        expense.merchant,
                                                        expense.creator,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            </td>
                                            <td className="px-4 py-3">
                                                {expense.category}
                                            </td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                                {money(
                                                    expense.amount,
                                                    expense.currency,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                                {money(
                                                    expense.tax_amount,
                                                    expense.currency,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">
                                                {money(
                                                    expense.total,
                                                    expense.currency,
                                                )}
                                                <span className="ml-1 text-xs text-muted-foreground">
                                                    {expense.currency}
                                                </span>
                                            </td>
                                            <td className="px-2">
                                                <div className="flex gap-1">
                                                    {expense.receipt_url && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-11"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    expense.receipt_url
                                                                }
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                aria-label={t(
                                                                    'expenses.view_receipt',
                                                                )}
                                                            >
                                                                <FileImage />
                                                            </a>
                                                        </Button>
                                                    )}
                                                    {expense.can_delete && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-11"
                                                            aria-label={t(
                                                                'common.remove',
                                                            )}
                                                            onClick={() =>
                                                                remove(expense)
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
                <PaginationLinks links={expenses.links} />
            </div>
            <Dialog open={categoryOpen} onOpenChange={setCategoryOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t(
                                editingCategory
                                    ? 'expenses.edit_category'
                                    : 'expenses.add_category',
                            )}
                        </DialogTitle>
                        <DialogDescription>
                            {t('expenses.category_hint')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={saveCategory} className="space-y-4">
                        <FormField
                            id="category_name"
                            label={t('expenses.fields.name')}
                            error={categoryForm.errors.name}
                        >
                            <Input
                                id="category_name"
                                required
                                maxLength={80}
                                value={categoryForm.data.name}
                                onChange={(event) =>
                                    categoryForm.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        {editingCategory && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={categoryForm.data.is_active}
                                    onCheckedChange={(checked) =>
                                        categoryForm.setData(
                                            'is_active',
                                            checked === true,
                                        )
                                    }
                                />
                                {t('expenses.active')}
                            </label>
                        )}
                        <Button
                            type="submit"
                            disabled={categoryForm.processing}
                            className="min-h-11"
                        >
                            {t('common.save')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
