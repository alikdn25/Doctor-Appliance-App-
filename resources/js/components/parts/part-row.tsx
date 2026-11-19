import { Link, router } from '@inertiajs/react';
import { Check, PackageCheck, ShoppingCart, Trash2, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { PartItem, SupplierOption } from '@/components/parts/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showJob } from '@/routes/jobs';
import {
    arrived as arrivedRoute,
    bought as boughtRoute,
    destroy as destroyRoute,
    ordered as orderedRoute,
    update as updateRoute,
} from '@/routes/parts';

/**
 * One part (Parts page and job page): name, job and customer, appliance and model, then number, quantity and
 * supplier edited right in the row (saved when the field is left). The AI's number carries "check" until it is
 * corrected or confirmed. Buttons: Bought / Ordered (to order), Arrived (waiting).
 */
export function PartRow({
    part,
    suppliers,
    showJob: withJob = true,
}: {
    part: PartItem;
    suppliers: SupplierOption[];
    /** The Parts page shows the job and customer; the job page does not. */
    showJob?: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const [number, setNumber] = useState(part.part_number ?? '');
    const [quantity, setQuantity] = useState(String(part.quantity));
    const [busy, setBusy] = useState<string | null>(null);

    useEffect(() => {
        setNumber(part.part_number ?? '');
        setQuantity(String(part.quantity));
    }, [part.part_number, part.quantity]);

    const save = (data: Record<string, unknown>) =>
        router.put(updateRoute(part.id).url, data as never, {
            preserveScroll: true,
            preserveState: true,
        });

    const move = (key: string, url: string) =>
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(key),
                onFinish: () => setBusy(null),
            },
        );

    const remove = () => {
        if (!confirm(t('parts.confirm_remove'))) return;
        router.delete(destroyRoute(part.id).url, { preserveScroll: true });
    };

    const disabled = !part.can_edit;

    return (
        <li
            className={cn(
                'space-y-2 p-3',
                part.long_wait && 'bg-amber-50 dark:bg-amber-950/30',
            )}
            data-test="part-row"
            data-status={part.status}
        >
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <p className="font-semibold break-words">{part.name}</p>
                    {withJob && (
                        <p className="text-sm text-muted-foreground">
                            <Link
                                href={showJob(part.job.id)}
                                className="font-medium text-link underline-offset-4 hover:underline"
                            >
                                #{part.job.number}
                            </Link>
                            {part.job.customer && ` · ${part.job.customer}`}
                        </p>
                    )}
                    {part.job.appliance && (
                        <p className="text-xs text-muted-foreground">
                            {part.job.appliance}
                        </p>
                    )}
                    {part.status === 'ordered' && part.ordered_on && (
                        <p
                            className={cn(
                                'text-xs',
                                part.long_wait
                                    ? 'font-semibold text-amber-800 dark:text-amber-300'
                                    : 'text-muted-foreground',
                            )}
                            data-test="part-waiting"
                        >
                            {t('parts.ordered_on', {
                                date: time.dateOnly(part.ordered_on),
                            })}
                            {' · '}
                            {t('parts.days_waiting', {
                                count: part.days_waiting ?? 0,
                            })}
                        </p>
                    )}
                </div>
                {part.can_edit && (
                    <button
                        type="button"
                        className="flex size-10 shrink-0 items-center justify-center rounded-xl text-muted-foreground"
                        aria-label={t('parts.remove')}
                        onClick={remove}
                    >
                        <Trash2 className="size-4" />
                    </button>
                )}
            </div>

            <div className="grid grid-cols-[1fr_4.5rem] gap-2">
                <label className="block space-y-1">
                    <span className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                        {t('parts.number')}
                        {part.number_unverified && (
                            <span
                                className="rounded bg-amber-200 px-1 text-[10px] font-bold text-amber-950 uppercase"
                                title={t('parts.check_hint')}
                                data-test="part-check"
                            >
                                {t('parts.check')}
                            </span>
                        )}
                    </span>
                    <Input
                        value={number}
                        maxLength={100}
                        disabled={disabled}
                        className={cn(
                            part.number_unverified && 'border-amber-400',
                        )}
                        onChange={(e) => setNumber(e.target.value)}
                        onBlur={() =>
                            number.trim() !== (part.part_number ?? '') &&
                            save({ part_number: number.trim() || null })
                        }
                        data-test="part-number"
                    />
                </label>
                <label className="block space-y-1">
                    <span className="text-xs font-medium text-muted-foreground">
                        {t('parts.quantity')}
                    </span>
                    <Input
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={999}
                        value={quantity}
                        disabled={disabled}
                        onChange={(e) => setQuantity(e.target.value)}
                        onBlur={() => {
                            const qty = Math.max(1, Number(quantity) || 1);
                            setQuantity(String(qty));
                            if (qty !== part.quantity) save({ quantity: qty });
                        }}
                        data-test="part-quantity"
                    />
                </label>
            </div>
            {part.number_unverified && part.can_edit && (
                <button
                    type="button"
                    className="flex min-h-9 items-center gap-1 text-sm font-semibold text-amber-800 underline-offset-4 hover:underline dark:text-amber-300"
                    onClick={() => save({ confirm_number: true })}
                    data-test="part-confirm-number"
                >
                    <Check className="size-4" /> {t('parts.confirm_number')}
                </button>
            )}
            <label className="block space-y-1">
                <span className="text-xs font-medium text-muted-foreground">
                    {t('parts.supplier')}
                </span>
                <NativeSelect
                    value={part.supplier_id ?? ''}
                    disabled={disabled}
                    onChange={(e) =>
                        save({
                            supplier_id:
                                e.target.value === ''
                                    ? null
                                    : Number(e.target.value),
                        })
                    }
                    data-test="part-supplier"
                >
                    <option value="">{t('parts.choose_supplier')}</option>
                    {suppliers.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </NativeSelect>
            </label>

            {part.can_edit && part.status === 'to_order' && (
                <div className="grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        loading={busy === 'bought'}
                        onClick={() => move('bought', boughtRoute(part.id).url)}
                        data-test="part-bought"
                    >
                        <ShoppingCart /> {t('parts.bought')}
                    </Button>
                    <Button
                        variant="outline"
                        loading={busy === 'ordered'}
                        onClick={() =>
                            move('ordered', orderedRoute(part.id).url)
                        }
                        data-test="part-ordered"
                    >
                        <Truck /> {t('parts.ordered')}
                    </Button>
                </div>
            )}
            {part.can_edit && part.status === 'ordered' && (
                <Button
                    variant="outline"
                    className="w-full"
                    loading={busy === 'arrived'}
                    onClick={() => move('arrived', arrivedRoute(part.id).url)}
                    data-test="part-arrived"
                >
                    <PackageCheck /> {t('parts.arrived')}
                </Button>
            )}
            {part.status === 'on_hand' && (
                <p className="flex items-center gap-1 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                    <PackageCheck className="size-4" />
                    {t('parts.status.on_hand')}
                </p>
            )}
        </li>
    );
}
