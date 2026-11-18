import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/auto-messages';

type Settings = {
    auto_reply_enabled: boolean;
    auto_reply_text: string;
    auto_reply_delay_minutes: number;
    missed_call_reply_enabled: boolean;
    missed_call_reply_text: string;
    sms_reminder_hours: number;
    warranty_lookback_days: number;
};

function Card({
    title,
    hint,
    children,
}: {
    title: string;
    hint: string;
    children: ReactNode;
}) {
    return (
        <section className="da-card space-y-3 p-4">
            <div>
                <h2 className="font-semibold">{title}</h2>
                <p className="text-sm text-muted-foreground">{hint}</p>
            </div>
            {children}
        </section>
    );
}

/**
 * Company → Auto messages (owner's task of November 18): auto reply to texts, the missed-call text, reminders about
 * unanswered texts and the "Possible warranty" window.
 */
export default function AutoMessages({
    settings,
    delays,
    automatic,
    missedCallsAvailable,
}: {
    settings: Settings;
    delays: number[];
    automatic: boolean;
    missedCallsAvailable: boolean;
}) {
    const t = useTrans();
    const form = useForm<Settings>(settings);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(update().url, { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('automation.settings.title')} />
            <ScreenHeader title={t('automation.settings.title')} />

            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6"
            >
                <p className="text-sm text-muted-foreground">
                    {t('automation.settings.description')}
                </p>
                {!automatic && (
                    <p
                        role="status"
                        className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900"
                    >
                        {t('automation.settings.needs_automatic')}
                    </p>
                )}

                <Card
                    title={t('automation.settings.auto_reply')}
                    hint={t('automation.settings.auto_reply_hint')}
                >
                    <label className="flex min-h-11 items-center gap-3 text-sm font-medium">
                        <Checkbox
                            checked={form.data.auto_reply_enabled}
                            onCheckedChange={(c) =>
                                form.setData('auto_reply_enabled', c === true)
                            }
                            data-test="auto-reply-enabled"
                        />
                        {t('automation.settings.auto_reply')}
                    </label>
                    <div className="space-y-1">
                        <Label htmlFor="auto-reply-text">
                            {t('automation.settings.text')}
                        </Label>
                        <Textarea
                            id="auto-reply-text"
                            value={form.data.auto_reply_text}
                            maxLength={480}
                            rows={3}
                            onChange={(e) =>
                                form.setData('auto_reply_text', e.target.value)
                            }
                        />
                        <InputError message={form.errors.auto_reply_text} />
                    </div>
                    <div className="space-y-1">
                        <p className="text-sm font-medium">
                            {t('automation.settings.delay')}
                        </p>
                        <SegmentedControl
                            label={t('automation.settings.delay')}
                            className="flex w-full"
                            value={String(form.data.auto_reply_delay_minutes)}
                            onChange={(value) =>
                                form.setData(
                                    'auto_reply_delay_minutes',
                                    Number(value),
                                )
                            }
                            options={delays.map((n) => ({
                                value: String(n),
                                label:
                                    n === 0
                                        ? t('automation.settings.immediately')
                                        : t('automation.settings.minutes', {
                                              n,
                                          }),
                            }))}
                        />
                    </div>
                </Card>

                <Card
                    title={t('automation.settings.missed_call')}
                    hint={t('automation.settings.missed_call_hint')}
                >
                    {!missedCallsAvailable && (
                        <p className="text-sm text-amber-800">
                            {t('automation.settings.missed_call_unavailable')}
                        </p>
                    )}
                    <label className="flex min-h-11 items-center gap-3 text-sm font-medium">
                        <Checkbox
                            checked={form.data.missed_call_reply_enabled}
                            onCheckedChange={(c) =>
                                form.setData(
                                    'missed_call_reply_enabled',
                                    c === true,
                                )
                            }
                        />
                        {t('automation.settings.missed_call')}
                    </label>
                    <div className="space-y-1">
                        <Label htmlFor="missed-call-text">
                            {t('automation.settings.text')}
                        </Label>
                        <Textarea
                            id="missed-call-text"
                            value={form.data.missed_call_reply_text}
                            maxLength={480}
                            rows={3}
                            onChange={(e) =>
                                form.setData(
                                    'missed_call_reply_text',
                                    e.target.value,
                                )
                            }
                        />
                    </div>
                </Card>

                <Card
                    title={t('automation.settings.reminders')}
                    hint={t('automation.settings.reminders_hint')}
                >
                    <div className="max-w-40 space-y-1">
                        <Label htmlFor="reminder-hours">
                            {t('automation.settings.every_hours')}
                        </Label>
                        <Input
                            id="reminder-hours"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={24}
                            value={form.data.sms_reminder_hours}
                            onChange={(e) =>
                                form.setData(
                                    'sms_reminder_hours',
                                    Number(e.target.value),
                                )
                            }
                        />
                        <InputError message={form.errors.sms_reminder_hours} />
                    </div>
                </Card>

                <Card
                    title={t('automation.settings.warranty')}
                    hint={t('automation.settings.warranty_hint')}
                >
                    <div className="max-w-40 space-y-1">
                        <Label htmlFor="warranty-days">
                            {t('automation.settings.warranty_days')}
                        </Label>
                        <Input
                            id="warranty-days"
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={730}
                            value={form.data.warranty_lookback_days}
                            onChange={(e) =>
                                form.setData(
                                    'warranty_lookback_days',
                                    Number(e.target.value),
                                )
                            }
                        />
                        <InputError
                            message={form.errors.warranty_lookback_days}
                        />
                    </div>
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

AutoMessages.layout = {
    breadcrumbs: [{ title: 'automation.settings.title', href: edit() }],
};
