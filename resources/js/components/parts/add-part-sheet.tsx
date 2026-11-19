import { useForm } from '@inertiajs/react';
import { Package } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import type { SupplierOption } from '@/components/parts/types';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTrans } from '@/lib/i18n';
import { store } from '@/routes/jobs/parts';
import type { Option } from '@/types';

/**
 * "Part needed" on the job and "+" on the Parts page: the part's name, and when known its number, quantity and
 * supplier. It goes to the Order list; the job moves to Need to order parts.
 */
export function AddPartSheet({
    open,
    onOpenChange,
    jobId,
    jobs = [],
    suppliers,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The job page's job; the Parts page picks one. */
    jobId?: number;
    jobs?: Option[];
    suppliers: SupplierOption[];
}) {
    const t = useTrans();
    const form = useForm({
        job: jobId ? String(jobId) : '',
        name: '',
        part_number: '',
        quantity: '1',
        supplier_id: '',
    });

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({
            job: jobId ? String(jobId) : '',
            name: '',
            part_number: '',
            quantity: '1',
            supplier_id: '',
        });
        // Only when the sheet opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.job === '') return;
        form.transform((d) => ({
            name: d.name,
            part_number: d.part_number || null,
            quantity: Number(d.quantity) || 1,
            supplier_id: d.supplier_id === '' ? null : Number(d.supplier_id),
        }));
        form.post(store(Number(form.data.job)).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[92dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="add-part-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle className="flex items-center gap-2">
                        <Package className="size-5" /> {t('parts.add_title')}
                    </SheetTitle>
                    <SheetDescription>{t('parts.add_hint')}</SheetDescription>
                </SheetHeader>
                <form onSubmit={submit} className="space-y-3">
                    {!jobId && (
                        <FormField id="part-job" label={t('parts.for_job')}>
                            <NativeSelect
                                id="part-job"
                                value={form.data.job}
                                onChange={(e) =>
                                    form.setData('job', e.target.value)
                                }
                            >
                                <option value="">
                                    {t('parts.choose_job')}
                                </option>
                                {jobs.map((job) => (
                                    <option key={job.value} value={job.value}>
                                        {job.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    )}
                    <FormField
                        id="part-name"
                        label={t('parts.name')}
                        error={form.errors.name}
                    >
                        <Input
                            id="part-name"
                            autoFocus
                            value={form.data.name}
                            maxLength={255}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid grid-cols-[1fr_5rem] gap-2">
                        <FormField
                            id="part-number"
                            label={t('parts.number')}
                            error={form.errors.part_number}
                        >
                            <Input
                                id="part-number"
                                value={form.data.part_number}
                                maxLength={100}
                                onChange={(e) =>
                                    form.setData('part_number', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="part-qty"
                            label={t('parts.quantity')}
                            error={form.errors.quantity}
                        >
                            <Input
                                id="part-qty"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={999}
                                value={form.data.quantity}
                                onChange={(e) =>
                                    form.setData('quantity', e.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <FormField
                        id="part-supplier"
                        label={t('parts.supplier')}
                        error={form.errors.supplier_id}
                    >
                        <NativeSelect
                            id="part-supplier"
                            value={form.data.supplier_id}
                            onChange={(e) =>
                                form.setData('supplier_id', e.target.value)
                            }
                        >
                            <option value="">
                                {t('parts.choose_supplier')}
                            </option>
                            {suppliers.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        disabled={
                            form.data.name.trim() === '' || form.data.job === ''
                        }
                        loading={form.processing}
                        data-test="add-part-save"
                    >
                        {t('parts.save')}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>
    );
}
