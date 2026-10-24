import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Download,
    FileImage,
    Pencil,
    Plus,
    SlidersHorizontal,
    Trash2,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useMoney } from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import { ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import { formatRange, PeriodPicker } from '@/components/ui/period-picker';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
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
import { destroy, edit, download, index } from '@/routes/expenses';
import type { ExpenseCategory, ExpenseRow } from './types';

type Filters = {
    from: string;
    to: string;
    category: string;
    search: string;
    employee: string;
};

export default function BusinessExpenses({
    expenses,
    categories,
    filters,
    currency,
    employees,
    employeeTotals,
    companyView,
    today,
}: {
    expenses: Paginated<ExpenseRow>;
    categories: ExpenseCategory[];
    filters: Filters;
    currency: string;
    employees: { id: number; name: string }[];
    employeeTotals: {
        id: number | null;
        name: string;
        currency: string;
        price: number;
        tax: number;
        total: number;
    }[];
    companyView: boolean;
    today: string;
}) {
    const t = useTrans();
    // My expenses (technicians): what was paid, no taxes.
    const amountFields = companyView
        ? (['price', 'tax', 'total'] as const)
        : (['total'] as const);
    const fieldLabel = (field: string) =>
        !companyView && field === 'total'
            ? 'expenses.fields.amount_paid'
            : `expenses.fields.${field}`;
    const money = useMoney(currency);
    const time = useCompanyTime();
    const { errors } = usePage().props;
    const [query, setQuery] = useState(filters);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const locale = usePage().props.auth.company?.locale ?? 'en-US';
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
        setFiltersOpen(false);
        router.get(
            index().url,
            filterParams({ ...query, from: filters.from, to: filters.to }),
            {
                preserveState: true,
                replace: true,
            },
        );
    }

    // Filters other than the period, as chips with ✕.
    const activeFilters = [
        filters.category && {
            key: 'category' as const,
            label:
                categories.find((c) => String(c.id) === filters.category)
                    ?.name ?? filters.category,
        },
        filters.employee && {
            key: 'employee' as const,
            label:
                employees.find((e) => String(e.id) === filters.employee)
                    ?.name ?? filters.employee,
        },
        filters.search && {
            key: 'search' as const,
            label: `“${filters.search}”`,
        },
    ].filter(
        (
            chip,
        ): chip is { key: 'category' | 'employee' | 'search'; label: string } =>
            Boolean(chip),
    );

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
            <ScreenHeader
                title={t('expenses.title')}
                subtitle={formatRange(filters.from, filters.to, today, locale)}
                actions={
                    <ActionSheet
                        title={t('expenses.title')}
                        items={[
                            {
                                key: 'export',
                                label: t('expenses.export'),
                                icon: <Download />,
                                onSelect: () => {
                                    window.location.href = download({
                                        query: filterParams(filters),
                                    }).url;
                                },
                            },
                        ]}
                    />
                }
            />
            <div className="mx-auto w-full max-w-4xl space-y-4 p-4 sm:p-6">
                <PeriodPicker
                    from={filters.from}
                    to={filters.to}
                    today={today}
                    onChange={(from, to, period) =>
                        router.get(
                            index().url,
                            {
                                ...filterParams({ ...filters, from, to }),
                                period,
                            },
                            {
                                preserveState: true,
                                preserveScroll: true,
                                replace: true,
                            },
                        )
                    }
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        className="h-10 rounded-full"
                        onClick={() => {
                            setQuery(filters);
                            setFiltersOpen(true);
                        }}
                        data-test="expense-filters"
                    >
                        <SlidersHorizontal />
                        {activeFilters.length > 0
                            ? t('expenses.filters_count', {
                                  count: activeFilters.length,
                              })
                            : t('expenses.filters')}
                    </Button>
                    {activeFilters.map((chip) => (
                        <button
                            key={chip.key}
                            type="button"
                            className="da-chip da-press inline-flex h-10 items-center gap-1.5 rounded-full px-3.5 text-sm font-medium"
                            aria-pressed="true"
                            aria-label={t('expenses.remove_filter', {
                                filter: chip.label,
                            })}
                            onClick={() =>
                                router.get(
                                    index().url,
                                    filterParams({
                                        ...filters,
                                        [chip.key]: '',
                                    }),
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            {chip.label}
                            <X className="size-4" aria-hidden="true" />
                        </button>
                    ))}
                </div>
                <p className="text-sm text-muted-foreground">
                    {t(
                        companyView
                            ? 'expenses.company_hint'
                            : 'expenses.own_hint',
                    )}
                </p>
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
                                                className={`grid gap-2 py-1 text-xs ${companyView ? 'grid-cols-3' : 'grid-cols-1'}`}
                                            >
                                                {amountFields.map((field) => (
                                                    <div key={field}>
                                                        <span className="text-muted-foreground">
                                                            {t(
                                                                fieldLabel(
                                                                    field,
                                                                ),
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
                                            {amountFields.map((field) => (
                                                <th
                                                    key={field}
                                                    className="px-4 py-3 text-right"
                                                >
                                                    {t(fieldLabel(field))}
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
                                                        {companyView && (
                                                            <>
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
                                                            </>
                                                        )}
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
                                    <div
                                        className={`grid gap-2 text-xs ${companyView ? 'grid-cols-3' : 'grid-cols-1'}`}
                                    >
                                        {amountFields.map((field) => (
                                            <div key={field}>
                                                <span className="text-muted-foreground">
                                                    {t(fieldLabel(field))}
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
                                                ...amountFields,
                                            ] as const
                                        ).map((field) => (
                                            <th
                                                key={field}
                                                className={`px-4 py-3 ${['price', 'tax', 'total'].includes(field) ? 'text-right' : 'text-left'}`}
                                            >
                                                {t(fieldLabel(field))}
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
                                            {companyView && (
                                                <>
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
                                                </>
                                            )}
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

                {companyView && employeeTotals.length > 0 && (
                    <details
                        className="da-card group overflow-hidden"
                        data-test="expenses-by-employee"
                    >
                        <summary className="flex min-h-14 cursor-pointer list-none items-center gap-3 px-4 py-2 font-semibold [&::-webkit-details-marker]:hidden">
                            <span className="flex-1">
                                {t('expenses.by_employee')}
                            </span>
                            <ChevronDown className="size-5 transition-transform group-open:rotate-180" />
                        </summary>
                        <ul className="divide-y border-t text-sm">
                            {employeeTotals.map((employee) => (
                                <li
                                    key={`${employee.id}-${employee.currency}`}
                                    className="flex flex-wrap items-baseline justify-between gap-x-3 px-4 py-2.5"
                                >
                                    {employee.id ? (
                                        <Link
                                            className="font-medium underline"
                                            href={index({
                                                query: filterParams({
                                                    ...filters,
                                                    employee: String(
                                                        employee.id,
                                                    ),
                                                }),
                                            })}
                                        >
                                            {employee.name}
                                        </Link>
                                    ) : (
                                        <span className="font-medium">
                                            {employee.name}
                                        </span>
                                    )}
                                    <span className="text-muted-foreground tabular-nums">
                                        {t('expenses.employee_line', {
                                            price: money(
                                                employee.price,
                                                employee.currency,
                                            ),
                                            tax: money(
                                                employee.tax,
                                                employee.currency,
                                            ),
                                            total: money(
                                                employee.total,
                                                employee.currency,
                                            ),
                                        })}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                            {t('expenses.employee_hint')}
                        </p>
                    </details>
                )}
            </div>

            <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
                <SheetContent
                    side="bottom"
                    className="mx-auto max-w-3xl gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                >
                    <SheetHeader className="p-0 pr-8">
                        <SheetTitle>{t('expenses.filters')}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {t('expenses.filters')}
                        </SheetDescription>
                    </SheetHeader>
                    <form onSubmit={apply} className="space-y-3">
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
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        {companyView && (
                            <FormField
                                id="employee"
                                label={t('expenses.fields.employee')}
                                error={errors.employee}
                            >
                                <NativeSelect
                                    id="employee"
                                    value={query.employee}
                                    onChange={(event) =>
                                        setQuery({
                                            ...query,
                                            employee: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">
                                        {t('expenses.all_employees')}
                                    </option>
                                    {employees.map((employee) => (
                                        <option
                                            key={employee.id}
                                            value={employee.id}
                                        >
                                            {employee.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                        )}
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
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="h-11"
                                onClick={() =>
                                    setQuery({
                                        ...query,
                                        category: '',
                                        employee: '',
                                        search: '',
                                    })
                                }
                            >
                                {t('expenses.clear')}
                            </Button>
                            <Button type="submit" className="h-11">
                                {t('expenses.apply_filters')}
                            </Button>
                        </div>
                    </form>
                </SheetContent>
            </Sheet>

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
