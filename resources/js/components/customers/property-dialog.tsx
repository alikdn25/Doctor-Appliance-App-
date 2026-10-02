import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { store, update } from '@/routes/properties';
import { PropertyFields } from './property-fields';
import { emptyProperty, propertyToForm } from './types';
import type { PropertyData, PropertyFormData } from './types';

export function PropertyDialog({
    open,
    onOpenChange,
    customerId,
    property,
    customerName,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    customerId: number;
    customerName: string;
    property: PropertyData | null;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const phoneText = usePhone();
    const country = auth.company?.country ?? 'US';
    const form = useForm<PropertyFormData>(emptyProperty(country));
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData(
                property
                    ? propertyToForm(property, phoneText)
                    : emptyProperty(country),
            );
        }
        // Reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, property]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (property) {
            form.put(update(property.id).url, options);
        } else {
            form.post(store(customerId).url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {property ? t('properties.edit') : t('properties.add')}
                    </DialogTitle>
                    <DialogDescription>{customerName}</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4">
                    <PropertyFields
                        data={form.data}
                        onChange={(patch) =>
                            form.setData({ ...form.data, ...patch })
                        }
                        errors={errors}
                    />
                    <label className="flex min-h-9 items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.is_primary}
                            onCheckedChange={(c) =>
                                form.setData('is_primary', c === true)
                            }
                        />
                        {t('properties.is_primary')}
                    </label>
                    <Button type="submit" disabled={form.processing}>
                        {t('common.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
