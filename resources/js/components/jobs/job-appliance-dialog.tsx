import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import {
    ApplianceImage,
    ApplianceTypePicker,
} from '@/components/appliance-image';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import type { ApplianceItem } from '@/components/jobs/types';
import { applianceTitle } from '@/components/jobs/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { store, update } from '@/routes/jobs/appliances';
import type { Option } from '@/types';

type FormData = {
    type: string;
    manufacturer: string;
    model_number: string;
    serial_number: string;
};

const toForm = (a: ApplianceItem | null): FormData => ({
    type: a?.type ?? 'washer',
    manufacturer: a?.manufacturer ?? '',
    model_number: a?.model_number ?? '',
    serial_number: a?.serial_number ?? '',
});

/**
 * Field edits of appliances on a job: add one (new, or already at the address),
 * or correct manufacturer, model and serial number of a linked one.
 */
export function JobApplianceDialog({
    open,
    onOpenChange,
    jobId,
    appliance,
    otherAppliances,
    applianceTypes,
    manufacturers,
    address,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    appliance: ApplianceItem | null;
    otherAppliances: ApplianceItem[];
    applianceTypes: Option[];
    manufacturers: string[];
    address: string;
}) {
    const t = useTrans();
    const form = useForm<FormData>(toForm(appliance));
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData(toForm(appliance));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, appliance]);

    const options = {
        preserveScroll: true,
        onSuccess: () => onOpenChange(false),
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (appliance) {
            form.transform(({ manufacturer, model_number, serial_number }) => ({
                manufacturer,
                model_number,
                serial_number,
            }));
            form.put(update([jobId, appliance.id]).url, options);
        } else {
            form.transform((data) => data);
            form.post(store(jobId).url, options);
        }
    };

    const link = (id: number) =>
        router.post(store(jobId).url, { appliance_id: id }, options);

    const field = (
        name: 'manufacturer' | 'model_number' | 'serial_number',
        extra: Record<string, string> = {},
    ) => (
        <FormField
            id={`job-appliance-${name}`}
            label={t(`appliances.fields.${name}`)}
            error={errors[name]}
        >
            <Input
                id={`job-appliance-${name}`}
                value={form.data[name]}
                autoComplete="off"
                onChange={(e) => form.setData(name, e.target.value)}
                {...extra}
            />
        </FormField>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {appliance
                            ? t('jobs.appliance.edit')
                            : t('jobs.appliance.add')}
                    </DialogTitle>
                    <DialogDescription>
                        {appliance ? applianceTitle(appliance) : address}
                    </DialogDescription>
                </DialogHeader>

                {!appliance && otherAppliances.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">
                            {t('jobs.appliance.add_existing')}
                        </h3>
                        <ul className="divide-y rounded-md border">
                            {otherAppliances.map((a) => (
                                <li
                                    key={a.id}
                                    className="flex items-center gap-2 px-3 py-2"
                                >
                                    <ApplianceImage
                                        type={a.type}
                                        className="size-10"
                                    />
                                    <span className="min-w-0 flex-1 text-sm">
                                        <span className="font-medium">
                                            {applianceTitle(a)}
                                        </span>
                                        {a.model_number && (
                                            <span className="text-muted-foreground">
                                                {' · '}
                                                {a.model_number}
                                            </span>
                                        )}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => link(a.id)}
                                    >
                                        {t('jobs.appliance.link')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <h3 className="pt-2 text-sm font-medium">
                            {t('jobs.appliance.add_new')}
                        </h3>
                    </div>
                )}

                <form onSubmit={submit} className="grid gap-4">
                    {!appliance && (
                        <div className="space-y-2">
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
                    )}
                    {field('manufacturer', { list: 'job-appliance-brands' })}
                    <datalist id="job-appliance-brands">
                        {manufacturers.map((m) => (
                            <option key={m} value={m} />
                        ))}
                    </datalist>
                    {field('model_number', { autoCapitalize: 'characters' })}
                    {field('serial_number', { autoCapitalize: 'characters' })}
                    <Button type="submit" disabled={form.processing}>
                        {t('common.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
