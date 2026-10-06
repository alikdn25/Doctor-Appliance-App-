import { Head, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    currencySymbol,
    formatMoney,
    fromMinor,
    toMinor,
} from '@/components/billing/money';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/services';
import type { Option } from '@/types';

type Service = {
    id: number | null;
    name: string;
    description: string | null;
    category: string | null;
    brand_ids: number[];
    unit_price: number | null;
    taxable: boolean;
    is_active: boolean;
    kind: string;
    part_number: string | null;
    supplier: string | null;
    unit: string | null;
    unit_cost: number | null;
    warranty_value: number | null;
    warranty_unit: string | null;
};

type Row = {
    key: number;
    id: number | null;
    name: string;
    description: string;
    category: string;
    brand_ids: number[];
    unit_price: string;
    taxable: boolean;
    is_active: boolean;
    kind: string;
    part_number: string;
    supplier: string | null;
    unit: string | null;
    unit_cost: string;
    warranty_value: string;
    warranty_unit: string;
};

let rowKey = 0;

/**
 * The company's services and prices, edited as one list (one card per service on a phone).
 */
export default function Services({
    services,
    warrantyUnits,
    brands,
}: {
    services: Service[];
    warrantyUnits: Option[];
    brands: { id: number; name: string }[];
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const currency = auth.company?.currency ?? 'USD';
    const symbol = currencySymbol(currency, auth.company?.locale);

    const form = useForm<{ services: Row[] }>({
        services: services.map((s) => ({
            key: ++rowKey,
            id: s.id,
            name: s.name,
            description: s.description ?? '',
            category: s.category ?? '',
            brand_ids: s.brand_ids,
            unit_price:
                s.unit_price === null ? '' : fromMinor(s.unit_price, currency),
            taxable: s.taxable,
            is_active: s.is_active,
            kind: s.kind,
            part_number: s.part_number ?? '',
            supplier: s.supplier,
            unit: s.unit,
            unit_cost:
                s.unit_cost === null ? '' : fromMinor(s.unit_cost, currency),
            warranty_value:
                s.warranty_value === null ? '' : String(s.warranty_value),
            warranty_unit: s.warranty_unit ?? 'days',
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const rows = form.data.services;
    // Services are folded to one line each; tap a line to edit it. New and invalid ones are open.
    const [openKeys, setOpenKeys] = useState<Set<number>>(new Set());
    const isOpen = (row: Row, index: number) =>
        openKeys.has(row.key) ||
        Object.keys(errors).some((key) => key.startsWith(`services.${index}.`));
    const toggle = (key: number) =>
        setOpenKeys((keys) => {
            const next = new Set(keys);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });

    const setRow = (index: number, patch: Partial<Row>) =>
        form.setData(
            'services',
            rows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            services: data.services.map((row) => ({
                id: row.id,
                name: row.name,
                description: row.description || null,
                category: row.category.trim() || null,
                brand_ids: row.brand_ids,
                unit_price: row.unit_price.replace(/[^\d.]/g, '') || null,
                taxable: row.taxable,
                is_active: row.is_active,
                kind: row.kind,
                part_number: row.part_number || null,
                supplier: row.supplier,
                unit: row.unit,
                unit_cost: row.unit_cost.replace(/[^\d.]/g, '') || null,
                warranty_value:
                    row.warranty_value === ''
                        ? null
                        : Number(row.warranty_value),
                warranty_unit: row.warranty_unit,
            })),
        }));
        form.put(update().url, { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('services.title')} />

            <form onSubmit={submit} className="max-w-3xl space-y-4 p-4">
                <PageHeader
                    title={t('services.title')}
                    description={t('services.description')}
                />
                <p className="text-sm text-muted-foreground">
                    {t('services.hint', { currency })}
                </p>

                {rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('services.empty')}
                    </p>
                )}

                <ul className="space-y-3">
                    {rows.map((row, i) => (
                        <li key={row.key} className="da-card space-y-3 p-3">
                            <button
                                type="button"
                                aria-expanded={isOpen(row, i)}
                                onClick={() => toggle(row.key)}
                                className="flex min-h-11 w-full items-center gap-3 text-left"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-semibold">
                                        {row.name || t('services.new')}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {[
                                            t(`billing.kinds.${row.kind}`),
                                            row.category,
                                            !row.is_active &&
                                                t('services.inactive'),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <span className="shrink-0 font-semibold tabular-nums">
                                    {row.unit_price.trim() === ''
                                        ? t('services.no_price')
                                        : Number.isNaN(Number(row.unit_price))
                                          ? row.unit_price
                                          : formatMoney(
                                                toMinor(
                                                    row.unit_price,
                                                    currency,
                                                ),
                                                currency,
                                                auth.company?.locale,
                                            )}
                                </span>
                                <ChevronDown
                                    className={`size-5 shrink-0 transition-transform ${isOpen(row, i) ? 'rotate-180' : ''}`}
                                    aria-hidden="true"
                                />
                            </button>
                            {isOpen(row, i) && (
                                <>
                                    <div className="flex items-start gap-2">
                                        <label className="grid flex-1 gap-1 text-xs font-semibold text-muted-foreground">
                                            {t('services.fields.name')}
                                            <Input
                                                value={row.name}
                                                onChange={(e) =>
                                                    setRow(i, {
                                                        name: e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[`services.${i}.name`]
                                                }
                                            />
                                        </label>
                                        <label className="grid w-36 gap-1 text-xs font-semibold text-muted-foreground">
                                            {t('services.fields.unit_price')}
                                            <div className="relative">
                                                <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                                                    {symbol}
                                                </span>
                                                <Input
                                                    placeholder={t(
                                                        'services.price_placeholder',
                                                    )}
                                                    inputMode="decimal"
                                                    style={{
                                                        paddingLeft: `${symbol.length * 0.6 + 1}rem`,
                                                    }}
                                                    value={row.unit_price}
                                                    onChange={(e) =>
                                                        setRow(i, {
                                                            unit_price:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                            </div>
                                            <InputError
                                                message={
                                                    errors[
                                                        `services.${i}.unit_price`
                                                    ]
                                                }
                                            />
                                        </label>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="mt-5 size-10"
                                            aria-label={t('services.remove')}
                                            onClick={() =>
                                                form.setData(
                                                    'services',
                                                    rows.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    <Input
                                        aria-label={t(
                                            'services.fields.description',
                                        )}
                                        placeholder={t(
                                            'services.fields.description',
                                        )}
                                        value={row.description}
                                        onChange={(e) =>
                                            setRow(i, {
                                                description: e.target.value,
                                            })
                                        }
                                    />
                                    <Input
                                        aria-label={t(
                                            'services.fields.category',
                                        )}
                                        placeholder={t(
                                            'services.fields.category',
                                        )}
                                        list="price-book-categories"
                                        maxLength={80}
                                        value={row.category}
                                        onChange={(e) =>
                                            setRow(i, {
                                                category: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors[`services.${i}.category`]
                                        }
                                    />
                                    {brands.length > 0 && (
                                        <fieldset className="space-y-2 rounded-lg bg-muted/30 p-3">
                                            <legend className="text-sm font-medium">
                                                {t(
                                                    'services.brand_availability',
                                                )}
                                            </legend>
                                            <p className="text-xs text-muted-foreground">
                                                {t('services.all_brands_hint')}
                                            </p>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setRow(i, { brand_ids: [] })
                                                }
                                            >
                                                {t('services.all_brands')}
                                            </Button>
                                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                                {brands.map((brand) => (
                                                    <label
                                                        key={brand.id}
                                                        className="flex min-h-10 items-center gap-2 text-sm"
                                                    >
                                                        <Checkbox
                                                            checked={row.brand_ids.includes(
                                                                brand.id,
                                                            )}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                setRow(i, {
                                                                    brand_ids:
                                                                        checked ===
                                                                        true
                                                                            ? [
                                                                                  ...row.brand_ids,
                                                                                  brand.id,
                                                                              ]
                                                                            : row.brand_ids.filter(
                                                                                  (
                                                                                      id,
                                                                                  ) =>
                                                                                      id !==
                                                                                      brand.id,
                                                                              ),
                                                                })
                                                            }
                                                        />
                                                        {brand.name}
                                                    </label>
                                                ))}
                                            </div>
                                            <InputError
                                                message={
                                                    errors[
                                                        `services.${i}.brand_ids`
                                                    ]
                                                }
                                            />
                                        </fieldset>
                                    )}
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <NativeSelect
                                            aria-label={t(
                                                'billing.kinds.service',
                                            )}
                                            value={row.kind}
                                            onChange={(e) =>
                                                setRow(i, {
                                                    kind: e.target.value,
                                                })
                                            }
                                        >
                                            {(
                                                [
                                                    'service',
                                                    'part',
                                                    'material',
                                                ] as const
                                            ).map((k) => (
                                                <option key={k} value={k}>
                                                    {t(`billing.kinds.${k}`)}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        {row.kind !== 'service' && (
                                            <Input
                                                aria-label={t(
                                                    'billing.line.part_number',
                                                )}
                                                placeholder={t(
                                                    'billing.line.part_number',
                                                )}
                                                value={row.part_number}
                                                onChange={(e) =>
                                                    setRow(i, {
                                                        part_number:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                        {row.kind !== 'service' && (
                                            <Input
                                                aria-label={t(
                                                    'billing.line.cost',
                                                )}
                                                placeholder={t(
                                                    'billing.line.cost',
                                                )}
                                                inputMode="decimal"
                                                value={row.unit_cost}
                                                onChange={(e) =>
                                                    setRow(i, {
                                                        unit_cost:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                        <div className="col-span-2 grid grid-cols-[6rem_1fr] gap-2 sm:col-span-1">
                                            <Input
                                                aria-label={t(
                                                    'billing.warranty',
                                                )}
                                                placeholder={t(
                                                    'services.warranty_default',
                                                )}
                                                inputMode="numeric"
                                                value={row.warranty_value}
                                                onChange={(e) =>
                                                    setRow(i, {
                                                        warranty_value:
                                                            e.target.value.replace(
                                                                /\D/g,
                                                                '',
                                                            ),
                                                    })
                                                }
                                            />
                                            <NativeSelect
                                                aria-label={t(
                                                    'billing.warranty',
                                                )}
                                                value={row.warranty_unit}
                                                onChange={(e) =>
                                                    setRow(i, {
                                                        warranty_unit:
                                                            e.target.value,
                                                    })
                                                }
                                            >
                                                {warrantyUnits.map((u) => (
                                                    <option
                                                        key={u.value}
                                                        value={u.value}
                                                    >
                                                        {u.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </div>
                                    </div>
                                    <div className="flex gap-4">
                                        <label className="flex min-h-9 items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={row.taxable}
                                                onCheckedChange={(c) =>
                                                    setRow(i, {
                                                        taxable: c === true,
                                                    })
                                                }
                                            />
                                            {t('services.fields.taxable')}
                                        </label>
                                        <label className="flex min-h-9 items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={row.is_active}
                                                onCheckedChange={(c) =>
                                                    setRow(i, {
                                                        is_active: c === true,
                                                    })
                                                }
                                            />
                                            {t('services.fields.is_active')}
                                        </label>
                                    </div>
                                </>
                            )}
                        </li>
                    ))}
                </ul>

                <Button
                    type="button"
                    variant="outline"
                    className="h-11 w-full"
                    onClick={() => {
                        const key = ++rowKey;
                        setOpenKeys((keys) => new Set(keys).add(key));
                        form.setData('services', [
                            ...rows,
                            {
                                key,
                                id: null,
                                name: '',
                                description: '',
                                category: '',
                                brand_ids: [],
                                unit_price: '',
                                taxable: true,
                                is_active: true,
                                kind: 'service',
                                part_number: '',
                                supplier: null,
                                unit: null,
                                unit_cost: '',
                                warranty_value: '',
                                warranty_unit: 'days',
                            },
                        ]);
                    }}
                >
                    <Plus /> {t('services.add')}
                </Button>

                {/* Shown once something changed; it replaces the tab bar on a phone (da-pinned). */}
                {form.isDirty && (
                    <div className="da-pinned sticky bottom-0 z-30 -mx-4 border-t bg-background/95 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur md:bottom-2 md:mx-0 md:rounded-2xl md:border">
                        <Button
                            type="submit"
                            className="h-12 w-full"
                            disabled={form.processing}
                        >
                            {t('services.save_changes')}
                        </Button>
                    </div>
                )}
                <datalist id="price-book-categories">
                    {[...new Set(rows.map((row) => row.category.trim()))]
                        .filter(Boolean)
                        .sort()
                        .map((category) => (
                            <option key={category} value={category} />
                        ))}
                </datalist>
            </form>
        </>
    );
}

Services.layout = {
    breadcrumbs: [{ title: 'services.title', href: edit() }],
};
