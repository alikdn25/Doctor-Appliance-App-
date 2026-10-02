import { router, useForm } from '@inertiajs/react';
import { FileText, Link2, Plus, Trash2, TrendingUp } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useMoney } from '@/components/billing/money';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import {
    destroy as destroyCost,
    store as storeCost,
} from '@/routes/jobs/costs';
import { store as storeReceipt } from '@/routes/jobs/receipts';
import {
    destroy as destroyReceipt,
    link as linkReceipt,
} from '@/routes/receipts';
import type { Option } from '@/types';

export type JobCosts = {
    profit: {
        revenue: number;
        cost: number;
        fees: number;
        profit: number;
        margin: number | null;
    };
    currency: string;
    items: {
        id: number;
        kind: string;
        description: string;
        part_number: string | null;
        supplier: string | null;
        quantity: string;
        unit: string | null;
        total_cost: number;
    }[];
    receipts: {
        id: number;
        name: string;
        url: string;
        supplier: string | null;
        receipt_date: string | null;
        amount: number | null;
        jobs: number[];
        can_delete: boolean;
    }[];
    supplier_taxes: {
        id: number;
        name: string;
        rate: string;
        recoverable: boolean;
    }[];
    units: Option[];
};

/**
 * Profit of the job, its costs that are on no invoice, and supplier receipts. Office (and technicians when the
 * company allows them to see costs); never shown to customers.
 */
export function JobCostsSection({
    jobId,
    costs,
}: {
    jobId: number;
    costs: JobCosts;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const money = useMoney(costs.currency);
    const [adding, setAdding] = useState(false);
    const [linkTo, setLinkTo] = useState<Record<number, string>>({});
    const p = costs.profit;
    const form = useForm({
        kind: 'part',
        description: '',
        part_number: '',
        supplier: '',
        quantity: '1',
        unit: '',
        unit_cost: '',
    });
    const receipt = useForm<{
        file: File | null;
        supplier: string;
        receipt_date: string;
        amount: string;
    }>({ file: null, supplier: '', receipt_date: '', amount: '' });

    const addCost = (e: FormEvent) => {
        e.preventDefault();
        form.post(storeCost(jobId).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const upload = (file: File | null) => {
        if (!file) {
            return;
        }

        receipt.transform((d) => ({ ...d, file }));
        receipt.post(storeReceipt(jobId).url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => receipt.reset(),
        });
    };

    return (
        <section className="space-y-3 rounded-lg border p-4">
            <h2 className="flex items-center gap-2 text-base font-medium">
                <TrendingUp className="size-4" />
                {t('costs.title')}
            </h2>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {t('costs.revenue')}
                    </dt>
                    <dd className="tabular-nums">{money(p.revenue)}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {t('costs.cost')}
                    </dt>
                    <dd className="tabular-nums">{money(p.cost)}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {t('costs.fees')}
                    </dt>
                    <dd className="tabular-nums">{money(p.fees)}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {p.profit < 0 ? t('costs.loss') : t('costs.profit')}
                    </dt>
                    <dd
                        className={
                            p.profit < 0
                                ? 'font-semibold text-red-700 tabular-nums dark:text-red-400'
                                : 'font-semibold tabular-nums'
                        }
                    >
                        {money(p.profit)}
                        {p.margin !== null && ` · ${p.margin}%`}
                    </dd>
                </div>
            </dl>

            <div className="space-y-2">
                <h3 className="text-sm font-medium">{t('costs.job_costs')}</h3>
                <p className="text-xs text-muted-foreground">
                    {t('costs.job_costs_hint')}
                </p>
                {costs.items.length > 0 && (
                    <ul className="divide-y rounded-md border text-sm">
                        {costs.items.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-center gap-2 p-2"
                            >
                                <span className="min-w-0 flex-1">
                                    {item.description}
                                    <span className="block text-xs text-muted-foreground">
                                        {[
                                            t(`billing.kinds.${item.kind}`),
                                            item.part_number,
                                            item.supplier,
                                            `× ${item.quantity}${item.unit ? ` ${item.unit}` : ''}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <span className="tabular-nums">
                                    {money(item.total_cost)}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-9"
                                    aria-label={t('costs.remove_cost')}
                                    onClick={() =>
                                        router.delete(
                                            destroyCost({
                                                job: jobId,
                                                cost: item.id,
                                            }).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                {adding ? (
                    <form onSubmit={addCost} className="grid grid-cols-2 gap-2">
                        <NativeSelect
                            aria-label={t('billing.kinds.part')}
                            value={form.data.kind}
                            onChange={(e) =>
                                form.setData('kind', e.target.value)
                            }
                        >
                            <option value="part">
                                {t('billing.kinds.part')}
                            </option>
                            <option value="material">
                                {t('billing.kinds.material')}
                            </option>
                        </NativeSelect>
                        <Input
                            placeholder={t('billing.line.part_number')}
                            aria-label={t('billing.line.part_number')}
                            value={form.data.part_number}
                            onChange={(e) =>
                                form.setData('part_number', e.target.value)
                            }
                        />
                        <Input
                            className="col-span-2"
                            placeholder={t('billing.fields.description')}
                            aria-label={t('billing.fields.description')}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                        <Input
                            placeholder={t('billing.line.supplier')}
                            aria-label={t('billing.line.supplier')}
                            value={form.data.supplier}
                            onChange={(e) =>
                                form.setData('supplier', e.target.value)
                            }
                        />
                        <Input
                            placeholder={t('billing.fields.quantity')}
                            aria-label={t('billing.fields.quantity')}
                            inputMode="decimal"
                            value={form.data.quantity}
                            onChange={(e) =>
                                form.setData('quantity', e.target.value)
                            }
                        />
                        <Input
                            placeholder={t('billing.line.cost')}
                            aria-label={t('billing.line.cost')}
                            inputMode="decimal"
                            value={form.data.unit_cost}
                            onChange={(e) =>
                                form.setData('unit_cost', e.target.value)
                            }
                        />
                        {form.data.kind === 'material' && (
                            <NativeSelect
                                aria-label={t('billing.line.unit')}
                                value={form.data.unit}
                                onChange={(e) =>
                                    form.setData('unit', e.target.value)
                                }
                            >
                                <option value="">
                                    {t('billing.line.unit')}
                                </option>
                                {costs.units.map((u) => (
                                    <option key={u.value} value={u.value}>
                                        {u.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        <InputError
                            className="col-span-2"
                            message={Object.values(form.errors)[0]}
                        />
                        <Button
                            type="submit"
                            className="col-span-2"
                            disabled={form.processing}
                        >
                            {t('costs.add_cost')}
                        </Button>
                    </form>
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setAdding(true)}
                    >
                        <Plus /> {t('costs.add_cost')}
                    </Button>
                )}
            </div>

            <div className="space-y-2">
                <h3 className="text-sm font-medium">
                    {t('costs.receipts.title')}
                </h3>
                <p className="text-xs text-muted-foreground">
                    {t('costs.receipts.hint')}
                </p>
                {costs.receipts.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('costs.receipts.empty')}
                    </p>
                )}
                <ul className="space-y-2">
                    {costs.receipts.map((r) => (
                        <li
                            key={r.id}
                            className="space-y-1 rounded-md border p-2 text-sm"
                        >
                            <div className="flex items-center gap-2">
                                <FileText className="size-4 shrink-0" />
                                <a
                                    href={r.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="min-w-0 flex-1 truncate underline"
                                >
                                    {r.name}
                                </a>
                                {r.can_delete && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        aria-label={t('costs.receipts.delete')}
                                        onClick={() =>
                                            router.delete(
                                                destroyReceipt(r.id).url,
                                                {
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {[
                                    r.supplier,
                                    r.receipt_date
                                        ? time.dateOnly(r.receipt_date)
                                        : null,
                                    r.amount !== null ? money(r.amount) : null,
                                    t('costs.receipts.jobs', {
                                        numbers: r.jobs
                                            .map((n) => `#${n}`)
                                            .join(', '),
                                    }),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            <div className="flex gap-2">
                                <Input
                                    aria-label={t('costs.receipts.link')}
                                    placeholder={t('costs.receipts.link')}
                                    inputMode="numeric"
                                    className="h-8"
                                    value={linkTo[r.id] ?? ''}
                                    onChange={(e) =>
                                        setLinkTo({
                                            ...linkTo,
                                            [r.id]: e.target.value.replace(
                                                /\D/g,
                                                '',
                                            ),
                                        })
                                    }
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!linkTo[r.id]}
                                    onClick={() =>
                                        router.post(
                                            linkReceipt(r.id).url,
                                            { job_number: linkTo[r.id] },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Link2 /> {t('costs.receipts.link_action')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
                <label className="inline-flex">
                    <span className="sr-only">{t('costs.receipts.add')}</span>
                    <Input
                        type="file"
                        accept="image/*,application/pdf"
                        aria-label={t('costs.receipts.add')}
                        disabled={receipt.processing}
                        onChange={(e) => upload(e.target.files?.[0] ?? null)}
                    />
                </label>
                <InputError message={receipt.errors.file} />
            </div>
        </section>
    );
}
