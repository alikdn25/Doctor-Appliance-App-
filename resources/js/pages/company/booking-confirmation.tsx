import { Head, router, useForm } from '@inertiajs/react';
import { RotateCcw, Send } from 'lucide-react';
import { useRef } from 'react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea, setFieldValue } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { test, update } from '@/routes/company/booking-confirmation';
import { edit as settingsRoute } from '@/routes/company/settings';

type Settings = {
    enabled: boolean;
    by_sms: boolean;
    by_email: boolean;
    sms_text: string;
    email_subject: string;
    email_text: string;
    policy_with_fee: string;
    policy_without_fee: string;
    cancellation_hours: number | null;
    cancellation_fee: string;
};

type TextKey =
    | 'sms_text'
    | 'email_subject'
    | 'email_text'
    | 'policy_with_fee'
    | 'policy_without_fee';

/** The finished text with the example customer, as the server makes it. */
function render(
    template: string,
    data: Settings,
    sample: Record<string, string | null>,
    feeSymbol: string,
): string {
    const hours = data.cancellation_hours;
    const fee =
        data.cancellation_fee.trim() !== ''
            ? `${feeSymbol}${data.cancellation_fee.trim()}`
            : null;
    const policy =
        hours === null
            ? ''
            : (fee !== null ? data.policy_with_fee : data.policy_without_fee)
                  .replaceAll('{cancellation_hours}', String(hours))
                  .replaceAll('{cancellation_fee}', fee ?? '');
    const values: Record<string, string> = {
        ...Object.fromEntries(
            Object.entries(sample).map(([k, v]) => [k, v ?? '']),
        ),
        cancellation_policy: policy,
        cancellation_hours: hours === null ? '' : String(hours),
        cancellation_fee: fee ?? '',
    };

    return template
        .replace(/\{([a-z_]+)\}/g, (match, name: string) =>
            name in values ? values[name] : match,
        )
        .replace(/[ \t]{2,}/g, ' ')
        .replace(/\s+([,.!?])/g, '$1')
        .trim();
}

/**
 * Settings → Company → Booking confirmation (owner's task of November 25): what the customer gets after every new
 * booking, by SMS and email. Every text, the notice hours and the fee are the company's own; a tap on a placeholder
 * puts it where the cursor was; the preview shows the finished SMS and email with an example customer.
 */
export default function BookingConfirmationSettings({
    settings,
    defaults,
    placeholders,
    sample,
    feeSymbol,
}: {
    settings: Settings;
    defaults: Settings;
    placeholders: string[];
    sample: Record<string, string | null>;
    feeSymbol: string;
}) {
    const t = useTrans();
    const form = useForm<Settings>(settings);
    const fields = useRef<Partial<Record<TextKey, HTMLTextAreaElement | null>>>(
        {},
    );
    const lastField = useRef<TextKey>('sms_text');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(update().url, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const insert = (placeholder: string) => {
        const key = lastField.current;
        const field = fields.current[key];
        const token = `{${placeholder}}`;
        if (!field) {
            form.setData(key, `${form.data[key]}${token}`);

            return;
        }
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        const next =
            field.value.slice(0, start) + token + field.value.slice(end);
        setFieldValue(field, next);
        requestAnimationFrame(() => {
            field.focus();
            field.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const text = (key: TextKey, rows = 4) => (
        <FormField
            id={`bc-${key}`}
            label={t(`booking_confirmation.settings.${key}`)}
            error={form.errors[key]}
        >
            <Textarea
                id={`bc-${key}`}
                ref={(node) => {
                    fields.current[key] = node;
                }}
                rows={rows}
                translatable
                value={form.data[key]}
                onFocus={() => {
                    lastField.current = key;
                }}
                onChange={(e) => form.setData(key, e.target.value)}
                data-test={`bc-${key}`}
                actions={
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11"
                        onClick={() => form.setData(key, defaults[key])}
                        data-test={`bc-reset-${key}`}
                    >
                        <RotateCcw /> {t('booking_confirmation.settings.reset')}
                    </Button>
                }
            />
        </FormField>
    );

    const toggle = (key: 'enabled' | 'by_sms' | 'by_email') => (
        <div className="flex min-h-11 items-center gap-3">
            <Checkbox
                id={`bc-${key}`}
                checked={form.data[key]}
                onCheckedChange={(checked) =>
                    form.setData(key, checked === true)
                }
                data-test={`bc-${key}`}
            />
            <Label htmlFor={`bc-${key}`} className="text-base">
                {t(`booking_confirmation.settings.${key}`)}
            </Label>
        </div>
    );

    return (
        <>
            <Head title={t('booking_confirmation.settings.title')} />
            <ScreenHeader
                title={t('booking_confirmation.settings.title')}
                back={settingsRoute()}
            />

            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6"
                data-test="booking-confirmation-settings"
            >
                <section className="da-card space-y-2 p-4">
                    <p className="text-sm text-muted-foreground">
                        {t('booking_confirmation.settings.description')}
                    </p>
                    {toggle('enabled')}
                    <div className="space-y-1 pl-6">
                        {toggle('by_sms')}
                        {toggle('by_email')}
                    </div>
                </section>

                <section className="da-card space-y-4 p-4">
                    {text('sms_text', 6)}
                    {text('email_subject', 2)}
                    {text('email_text', 6)}
                    {text('policy_with_fee', 3)}
                    {text('policy_without_fee', 3)}
                    <div className="grid grid-cols-2 gap-3">
                        <FormField
                            id="bc-hours"
                            label={t(
                                'booking_confirmation.settings.cancellation_hours',
                            )}
                            error={form.errors.cancellation_hours}
                        >
                            <Input
                                id="bc-hours"
                                inputMode="numeric"
                                value={form.data.cancellation_hours ?? ''}
                                onChange={(e) =>
                                    form.setData(
                                        'cancellation_hours',
                                        e.target.value.trim() === ''
                                            ? null
                                            : Number(
                                                  e.target.value.replace(
                                                      /\D/g,
                                                      '',
                                                  ),
                                              ),
                                    )
                                }
                                data-test="bc-hours"
                            />
                        </FormField>
                        <FormField
                            id="bc-fee"
                            label={t(
                                'booking_confirmation.settings.cancellation_fee',
                                { symbol: feeSymbol },
                            )}
                            hint={t(
                                'booking_confirmation.settings.cancellation_fee_hint',
                            )}
                            error={form.errors.cancellation_fee}
                        >
                            <Input
                                id="bc-fee"
                                inputMode="decimal"
                                value={form.data.cancellation_fee}
                                onChange={(e) =>
                                    form.setData(
                                        'cancellation_fee',
                                        e.target.value,
                                    )
                                }
                                data-test="bc-fee"
                            />
                        </FormField>
                    </div>
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            {t('booking_confirmation.settings.placeholders')}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {placeholders.map((name) => (
                                <button
                                    key={name}
                                    type="button"
                                    // Keeps the cursor in the field it was in.
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => insert(name)}
                                    className="da-chip da-press min-h-10 rounded-full px-3 font-mono text-xs"
                                    data-test={`bc-placeholder-${name}`}
                                >
                                    {`{${name}}`}
                                </button>
                            ))}
                        </div>
                    </div>
                </section>

                <section
                    className="da-card space-y-3 p-4"
                    data-test="bc-preview"
                >
                    <h2 className="font-semibold">
                        {t('booking_confirmation.settings.preview')}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {t('booking_confirmation.settings.preview_hint')}
                    </p>
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {t('booking_confirmation.settings.preview_sms')}
                        </p>
                        <p
                            className="mt-1 max-w-sm rounded-2xl rounded-bl-sm bg-muted p-3 text-sm whitespace-pre-line"
                            data-test="bc-preview-sms"
                        >
                            {render(
                                form.data.sms_text,
                                form.data,
                                sample,
                                feeSymbol,
                            )}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {t('booking_confirmation.settings.preview_email')}
                        </p>
                        <div className="mt-1 rounded-xl border p-3 text-sm">
                            <p
                                className="font-semibold"
                                data-test="bc-preview-subject"
                            >
                                {render(
                                    form.data.email_subject,
                                    form.data,
                                    sample,
                                    feeSymbol,
                                )}
                            </p>
                            <p className="mt-2 whitespace-pre-line">
                                {render(
                                    form.data.email_text,
                                    form.data,
                                    sample,
                                    feeSymbol,
                                )}
                            </p>
                        </div>
                    </div>
                </section>

                <div className="grid gap-2 sm:grid-cols-2">
                    <Button
                        type="submit"
                        size="lg"
                        loading={form.processing}
                        data-test="bc-save"
                    >
                        {t('common.save')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="lg"
                        onClick={() =>
                            router.post(
                                test().url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                        data-test="bc-test"
                    >
                        <Send /> {t('booking_confirmation.settings.send_test')}
                    </Button>
                </div>
            </form>
        </>
    );
}
