import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { ApplianceTypePicker } from '@/components/appliance-image';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
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
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { store, update } from '@/routes/appliances';
import type { Option } from '@/types';

export type ApplianceData = {
    id: number;
    type: string;
    manufacturer: string | null;
    model_number: string | null;
    serial_number: string | null;
    rating_plate_url: string | null;
    install_date: string | null;
    purchase_date: string | null;
    warranty_expires_on: string | null;
    warranty_notes: string | null;
    notes: string | null;
};

type FormData = {
    type: string;
    manufacturer: string;
    model_number: string;
    serial_number: string;
    install_date: string;
    purchase_date: string;
    warranty_expires_on: string;
    warranty_notes: string;
    notes: string;
    rating_plate: File | null;
    remove_rating_plate: boolean;
};

const toForm = (a: ApplianceData | null): FormData => ({
    type: a?.type ?? 'washer',
    manufacturer: a?.manufacturer ?? '',
    model_number: a?.model_number ?? '',
    serial_number: a?.serial_number ?? '',
    install_date: a?.install_date ?? '',
    purchase_date: a?.purchase_date ?? '',
    warranty_expires_on: a?.warranty_expires_on ?? '',
    warranty_notes: a?.warranty_notes ?? '',
    notes: a?.notes ?? '',
    rating_plate: null,
    remove_rating_plate: false,
});

/**
 * Create an appliance at a property (propertyId) or edit an existing one (appliance).
 */
export function ApplianceDialog({
    open,
    onOpenChange,
    propertyId,
    appliance,
    description,
    applianceTypes,
    manufacturers,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    propertyId?: number;
    appliance: ApplianceData | null;
    description: string;
    applianceTypes: Option[];
    manufacturers: string[];
}) {
    const t = useTrans();
    const form = useForm<FormData>(toForm(appliance));
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData(toForm(appliance));
        }
        // Reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, appliance]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (appliance) {
            // Multipart forms are sent as POST with method spoofing.
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(update(appliance.id).url, options);
        } else if (propertyId) {
            form.transform((data) => data);
            form.post(store(propertyId).url, options);
        }
    };

    const field = (
        name:
            | 'manufacturer'
            | 'model_number'
            | 'serial_number'
            | 'install_date'
            | 'purchase_date'
            | 'warranty_expires_on',
        type = 'text',
        extra: Record<string, string> = {},
    ) => (
        <FormField
            id={`appliance-${name}`}
            label={t(`appliances.fields.${name}`)}
            error={errors[name]}
        >
            <Input
                id={`appliance-${name}`}
                type={type}
                value={form.data[name]}
                onChange={(e) => form.setData(name, e.target.value)}
                {...extra}
            />
        </FormField>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {appliance ? t('appliances.edit') : t('appliances.add')}
                    </DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2 sm:col-span-2">
                        <span className="text-[13px] font-semibold">
                            {t('appliances.fields.type')}
                        </span>
                        <ApplianceTypePicker
                            label={t('appliances.fields.type')}
                            options={applianceTypes}
                            value={form.data.type}
                            onChange={(v) => form.setData('type', v)}
                        />
                        <InputError message={errors.type} />
                    </div>
                    {field('manufacturer', 'text', {
                        list: 'appliance-manufacturers',
                        autoComplete: 'off',
                    })}
                    <datalist id="appliance-manufacturers">
                        {manufacturers.map((m) => (
                            <option key={m} value={m} />
                        ))}
                    </datalist>
                    {field('model_number', 'text', {
                        autoCapitalize: 'characters',
                        autoComplete: 'off',
                    })}
                    {field('serial_number', 'text', {
                        autoCapitalize: 'characters',
                        autoComplete: 'off',
                    })}

                    <FormField
                        id="appliance-rating_plate"
                        label={t('appliances.rating_plate')}
                        error={errors.rating_plate}
                        hint={t('appliances.rating_plate_hint')}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="appliance-rating_plate"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            capture="environment"
                            onChange={(e) =>
                                form.setData(
                                    'rating_plate',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </FormField>
                    {appliance?.rating_plate_url && (
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <Checkbox
                                checked={form.data.remove_rating_plate}
                                onCheckedChange={(c) =>
                                    form.setData(
                                        'remove_rating_plate',
                                        c === true,
                                    )
                                }
                            />
                            {t('appliances.remove_rating_plate')}
                        </label>
                    )}

                    {field('purchase_date', 'date')}
                    {field('install_date', 'date')}
                    {field('warranty_expires_on', 'date')}
                    <FormField
                        id="appliance-warranty_notes"
                        label={t('appliances.fields.warranty_notes')}
                        error={errors.warranty_notes}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            id="appliance-warranty_notes"
                            rows={2}
                            value={form.data.warranty_notes}
                            onChange={(e) =>
                                form.setData('warranty_notes', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="appliance-notes"
                        label={t('appliances.fields.notes')}
                        error={errors.notes}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            id="appliance-notes"
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </FormField>

                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="sm:col-span-2"
                    >
                        {t('common.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
