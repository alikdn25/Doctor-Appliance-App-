import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { currencySymbol, fromMinor } from '@/components/billing/money';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/services';

type Service = {
    id: number | null;
    name: string;
    description: string | null;
    unit_price: number | null;
    taxable: boolean;
    is_active: boolean;
};

type Row = {
    key: number;
    id: number | null;
    name: string;
    description: string;
    unit_price: string;
    taxable: boolean;
    is_active: boolean;
};

let rowKey = 0;

/**
 * The company's services and prices, edited as one list (one card per service on a phone).
 */
export default function Services({ services }: { services: Service[] }) {
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
            unit_price:
                s.unit_price === null ? '' : fromMinor(s.unit_price, currency),
            taxable: s.taxable,
            is_active: s.is_active,
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const rows = form.data.services;

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
                unit_price: row.unit_price.replace(/[^\d.]/g, '') || null,
                taxable: row.taxable,
                is_active: row.is_active,
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
                        <li
                            key={row.key}
                            className="space-y-2 rounded-lg border p-3"
                        >
                            <div className="flex gap-2">
                                <div className="flex-1">
                                    <Input
                                        aria-label={t('services.fields.name')}
                                        placeholder={t('services.fields.name')}
                                        value={row.name}
                                        onChange={(e) =>
                                            setRow(i, { name: e.target.value })
                                        }
                                    />
                                    <InputError
                                        message={errors[`services.${i}.name`]}
                                    />
                                </div>
                                <div className="w-32">
                                    <div className="relative">
                                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                                            {symbol}
                                        </span>
                                        <Input
                                            aria-label={t(
                                                'services.fields.unit_price',
                                            )}
                                            placeholder={t('services.no_price')}
                                            inputMode="decimal"
                                            style={{
                                                paddingLeft: `${symbol.length * 0.6 + 1}rem`,
                                            }}
                                            value={row.unit_price}
                                            onChange={(e) =>
                                                setRow(i, {
                                                    unit_price: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                    <InputError
                                        message={
                                            errors[`services.${i}.unit_price`]
                                        }
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-10"
                                    aria-label={t('services.remove')}
                                    onClick={() =>
                                        form.setData(
                                            'services',
                                            rows.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                            <Input
                                aria-label={t('services.fields.description')}
                                placeholder={t('services.fields.description')}
                                value={row.description}
                                onChange={(e) =>
                                    setRow(i, { description: e.target.value })
                                }
                            />
                            <div className="flex gap-4">
                                <label className="flex min-h-9 items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={row.taxable}
                                        onCheckedChange={(c) =>
                                            setRow(i, { taxable: c === true })
                                        }
                                    />
                                    {t('services.fields.taxable')}
                                </label>
                                <label className="flex min-h-9 items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={row.is_active}
                                        onCheckedChange={(c) =>
                                            setRow(i, { is_active: c === true })
                                        }
                                    />
                                    {t('services.fields.is_active')}
                                </label>
                            </div>
                        </li>
                    ))}
                </ul>

                <Button
                    type="button"
                    variant="outline"
                    className="h-11 w-full"
                    onClick={() =>
                        form.setData('services', [
                            ...rows,
                            {
                                key: ++rowKey,
                                id: null,
                                name: '',
                                description: '',
                                unit_price: '',
                                taxable: true,
                                is_active: true,
                            },
                        ])
                    }
                >
                    <Plus /> {t('services.add')}
                </Button>

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

Services.layout = {
    breadcrumbs: [{ title: 'services.title', href: edit() }],
};
