import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { edit as settings } from '@/routes/company/settings';
import { reset, update } from '@/routes/company/service-area';
import type { ServiceArea } from '@/types';

const SIDES = ['south', 'north', 'west', 'east'] as const;
type Side = (typeof SIDES)[number];

const text = (area: ServiceArea | null): Record<Side, string> =>
    Object.fromEntries(
        SIDES.map((side) => [side, area ? String(area[side]) : '']),
    ) as Record<Side, string>;

/**
 * Company → Service area: the south/north/west/east box Google address suggestions are limited to in every address
 * field. All four empty = anywhere in the company's country. "Reset to default" puts back the installation's
 * default box.
 */
export default function ServiceAreaPage({
    area,
    defaultArea,
}: {
    area: ServiceArea | null;
    defaultArea: ServiceArea | null;
}) {
    const t = useTrans();
    const form = useForm(text(area));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            Object.fromEntries(
                SIDES.map((side) => [side, data[side].trim() || null]),
            ),
        );
        form.put(update().url, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const resetToDefault = () =>
        router.post(
            reset().url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.setData(text(defaultArea));
                    form.setDefaults(text(defaultArea));
                },
            },
        );

    return (
        <>
            <Head title={t('company.service_area.title')} />
            <ScreenHeader
                title={t('company.service_area.title')}
                back={settings()}
            />

            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6"
                data-test="service-area"
            >
                <section className="da-card space-y-4 p-4">
                    <p className="text-sm text-muted-foreground">
                        {t('company.service_area.hint')}
                    </p>
                    <div className="grid grid-cols-2 gap-3">
                        {SIDES.map((side) => (
                            <FormField
                                key={side}
                                id={`service-area-${side}`}
                                label={t(`company.service_area.${side}`)}
                                error={form.errors[side]}
                            >
                                <Input
                                    id={`service-area-${side}`}
                                    inputMode="decimal"
                                    value={form.data[side]}
                                    onChange={(e) =>
                                        form.setData(side, e.target.value)
                                    }
                                    placeholder={
                                        defaultArea
                                            ? String(defaultArea[side])
                                            : undefined
                                    }
                                    data-test={`service-area-${side}`}
                                />
                            </FormField>
                        ))}
                    </div>
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        loading={form.processing}
                        data-test="service-area-save"
                    >
                        {t('company.service_area.save')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="lg"
                        className="w-full"
                        onClick={resetToDefault}
                        data-test="service-area-reset"
                    >
                        {t('company.service_area.reset')}
                    </Button>
                    {!defaultArea && (
                        <p className="text-xs text-muted-foreground">
                            {t('company.service_area.reset_none')}
                        </p>
                    )}
                </section>
            </form>
        </>
    );
}
