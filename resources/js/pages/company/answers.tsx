import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/answers';

type Settings = {
    diagnostic_fee: string;
    service_areas: string;
    hours_text: string;
    answer_notes: string;
    translate_to_english: boolean;
    auto_clarify: boolean;
    payment_reminder_template: string;
    outside_technicians: { name: string; phone: string }[];
};

function Card({
    title,
    hint,
    children,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <section className="da-card space-y-3 p-4">
            <div>
                <h2 className="font-semibold">{title}</h2>
                {hint && (
                    <p className="text-sm text-muted-foreground">{hint}</p>
                )}
            </div>
            {children}
        </section>
    );
}

/**
 * Company → Prices and answers (owner's task of November 19): what the AI may say in reply drafts, Russian →
 * English, clarifying questions sent automatically, the soft payment reminder and outside technicians.
 */
export default function Answers({
    settings,
    currency,
}: {
    settings: Settings;
    currency: string;
}) {
    const t = useTrans();
    const form = useForm<Settings>(settings);
    const errors = form.errors as Record<string, string | undefined>;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(update().url, { preserveScroll: true });
    };
    const text = (
        key: 'service_areas' | 'hours_text' | 'answer_notes',
        label: string,
        hint: string,
        rows = 2,
    ) => (
        <div className="space-y-1">
            <Label htmlFor={key}>{label}</Label>
            <Textarea
                id={key}
                rows={rows}
                placeholder={hint}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            <InputError message={errors[key]} />
        </div>
    );
    const techs = form.data.outside_technicians;

    return (
        <>
            <Head title={t('answers.settings.title')} />
            <ScreenHeader title={t('answers.settings.title')} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6"
            >
                <Card
                    title={t('answers.settings.title')}
                    hint={t('answers.settings.description')}
                >
                    <div className="max-w-48 space-y-1">
                        <Label htmlFor="diagnostic-fee">
                            {t('answers.settings.diagnostic_fee')} ({currency})
                        </Label>
                        <Input
                            id="diagnostic-fee"
                            inputMode="decimal"
                            value={form.data.diagnostic_fee}
                            onChange={(e) =>
                                form.setData('diagnostic_fee', e.target.value)
                            }
                            data-test="diagnostic-fee"
                        />
                        <InputError message={errors.diagnostic_fee} />
                    </div>
                    {text(
                        'service_areas',
                        t('answers.settings.service_areas'),
                        t('answers.settings.service_areas_hint'),
                    )}
                    {text(
                        'hours_text',
                        t('answers.settings.hours_text'),
                        t('answers.settings.hours_hint'),
                    )}
                    {text(
                        'answer_notes',
                        t('answers.settings.notes'),
                        t('answers.settings.notes_hint'),
                        4,
                    )}
                </Card>

                <Card title={t('answers.settings.ai_title')}>
                    <label className="flex min-h-11 items-start gap-3 text-sm">
                        <Checkbox
                            className="mt-1"
                            checked={form.data.translate_to_english}
                            onCheckedChange={(c) =>
                                form.setData('translate_to_english', c === true)
                            }
                        />
                        <span>
                            <span className="font-medium">
                                {t('answers.settings.translate')}
                            </span>
                            <br />
                            <span className="text-muted-foreground">
                                {t('answers.settings.translate_hint')}
                            </span>
                        </span>
                    </label>
                    <label className="flex min-h-11 items-start gap-3 text-sm">
                        <Checkbox
                            className="mt-1"
                            checked={form.data.auto_clarify}
                            onCheckedChange={(c) =>
                                form.setData('auto_clarify', c === true)
                            }
                            data-test="auto-clarify"
                        />
                        <span>
                            <span className="font-medium">
                                {t('answers.settings.auto_clarify')}
                            </span>
                            <br />
                            <span className="text-muted-foreground">
                                {t('answers.settings.auto_clarify_hint')}
                            </span>
                        </span>
                    </label>
                </Card>

                <Card
                    title={t('answers.settings.reminder_title')}
                    hint={t('answers.settings.reminder_hint')}
                >
                    <Textarea
                        rows={4}
                        maxLength={480}
                        value={form.data.payment_reminder_template}
                        onChange={(e) =>
                            form.setData(
                                'payment_reminder_template',
                                e.target.value,
                            )
                        }
                    />
                    <InputError message={errors.payment_reminder_template} />
                </Card>

                <Card
                    title={t('answers.settings.outside_title')}
                    hint={t('answers.settings.outside_hint')}
                >
                    {techs.map((tech, i) => (
                        <div key={i} className="flex items-end gap-2">
                            <div className="flex-1 space-y-1">
                                <Label htmlFor={`tech-name-${i}`}>
                                    {t('answers.settings.name')}
                                </Label>
                                <Input
                                    id={`tech-name-${i}`}
                                    value={tech.name}
                                    onChange={(e) =>
                                        form.setData(
                                            'outside_technicians',
                                            techs.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          name: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                            </div>
                            <div className="flex-1 space-y-1">
                                <Label htmlFor={`tech-phone-${i}`}>
                                    {t('answers.settings.phone')}
                                </Label>
                                <Input
                                    id={`tech-phone-${i}`}
                                    type="tel"
                                    inputMode="tel"
                                    value={tech.phone}
                                    onChange={(e) =>
                                        form.setData(
                                            'outside_technicians',
                                            techs.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          phone: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        errors[`outside_technicians.${i}.phone`]
                                    }
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={t('answers.settings.remove')}
                                onClick={() =>
                                    form.setData(
                                        'outside_technicians',
                                        techs.filter((_, j) => j !== i),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            form.setData('outside_technicians', [
                                ...techs,
                                { name: '', phone: '' },
                            ])
                        }
                    >
                        <Plus className="size-4" />
                        {t('answers.settings.add')}
                    </Button>
                </Card>

                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    {t('common.save')}
                </Button>
            </form>
        </>
    );
}

Answers.layout = {
    breadcrumbs: [{ title: 'answers.settings.title', href: edit() }],
};
