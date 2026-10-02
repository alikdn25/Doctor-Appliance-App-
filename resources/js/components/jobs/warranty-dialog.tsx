import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
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
import { update } from '@/routes/jobs/warranties';
import type { Option } from '@/types';

export type WarrantyLine = {
    id: number;
    invoice: string;
    description: string;
    kind: string;
    bill_to_customer: boolean;
    warranty_value: number;
    warranty_unit: string;
    warranty_ends_on: string | null;
};

/**
 * Warranty summary of a closed job: each line's length (0 = none), changeable, with "Apply to all lines".
 */
export function WarrantyDialog({
    open,
    onOpenChange,
    jobId,
    lines,
    units,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    lines: WarrantyLine[];
    units: Option[];
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const toForm = () =>
        lines.map((l) => ({
            id: l.id,
            warranty_value: String(l.warranty_value),
            warranty_unit: l.warranty_unit,
        }));
    const form = useForm({ items: toForm() });
    const [all, setAll] = useState({ value: '90', unit: 'days' });

    useEffect(() => {
        if (open) {
            form.setData({ items: toForm() });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, lines]);

    const set = (
        i: number,
        patch: Partial<{ warranty_value: string; warranty_unit: string }>,
    ) =>
        form.setData(
            'items',
            form.data.items.map((item, j) =>
                j === i ? { ...item, ...patch } : item,
            ),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            items: d.items.map((item) => ({
                ...item,
                warranty_value: Number(item.warranty_value || 0),
            })),
        }));
        form.put(update(jobId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[95svh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('jobs.warranty.title')}</DialogTitle>
                    <DialogDescription>
                        {t('jobs.warranty.hint')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-[4rem_1fr_auto] gap-2">
                        <Input
                            aria-label={t('billing.warranty')}
                            inputMode="numeric"
                            value={all.value}
                            onChange={(e) =>
                                setAll({
                                    ...all,
                                    value: e.target.value.replace(/\D/g, ''),
                                })
                            }
                        />
                        <NativeSelect
                            aria-label={t('billing.warranty')}
                            value={all.unit}
                            onChange={(e) =>
                                setAll({ ...all, unit: e.target.value })
                            }
                        >
                            {units.map((u) => (
                                <option key={u.value} value={u.value}>
                                    {u.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData(
                                    'items',
                                    form.data.items.map((item) => ({
                                        ...item,
                                        warranty_value: all.value || '0',
                                        warranty_unit: all.unit,
                                    })),
                                )
                            }
                        >
                            {t('jobs.warranty.apply_all')}
                        </Button>
                    </div>
                    <ul className="divide-y rounded-lg border">
                        {lines.map((line, i) => (
                            <li key={line.id} className="space-y-2 p-3 text-sm">
                                <p>
                                    <span className="text-xs text-muted-foreground uppercase">
                                        {t(`billing.kinds.${line.kind}`)} ·{' '}
                                        {line.invoice}
                                        {!line.bill_to_customer &&
                                            ` · ${t('billing.line.internal')}`}
                                    </span>
                                    <br />
                                    {line.description}
                                </p>
                                <div className="grid grid-cols-[4rem_1fr] gap-2">
                                    <Input
                                        aria-label={t('billing.warranty')}
                                        inputMode="numeric"
                                        value={
                                            form.data.items[i]
                                                ?.warranty_value ?? ''
                                        }
                                        onChange={(e) =>
                                            set(i, {
                                                warranty_value:
                                                    e.target.value.replace(
                                                        /\D/g,
                                                        '',
                                                    ),
                                            })
                                        }
                                    />
                                    <NativeSelect
                                        aria-label={t('billing.warranty')}
                                        value={
                                            form.data.items[i]?.warranty_unit ??
                                            'days'
                                        }
                                        onChange={(e) =>
                                            set(i, {
                                                warranty_unit: e.target.value,
                                            })
                                        }
                                    >
                                        {units.map((u) => (
                                            <option
                                                key={u.value}
                                                value={u.value}
                                            >
                                                {u.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                {line.warranty_ends_on && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('jobs.warranty.until', {
                                            date: time.dateOnly(
                                                line.warranty_ends_on,
                                            ),
                                        })}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                    <Button
                        type="submit"
                        className="h-11 w-full"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
