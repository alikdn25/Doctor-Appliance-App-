import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Phone } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import {
    edit,
    provision,
    registration as registrationRoute,
    update,
} from '@/routes/company/messaging';
import type { Option } from '@/types';

type Settings = {
    sms_mode: string;
    quiet_hours_start: string;
    quiet_hours_end: string;
    review_requests_default: boolean;
    review_request_delay_hours: number;
    review_request_cooldown_days: number;
};

type Template = { kind: string; label: string; text: string; default: string };

type Registration = {
    status: string;
    status_label: string;
    rejection_reason: string | null;
    business: Record<string, string>;
    business_types: Option[];
} | null;

const BUSINESS_FIELDS = [
    'legal_name',
    'business_type',
    'ein',
    'website',
    'street',
    'city',
    'region',
    'postal_code',
    'contact_first_name',
    'contact_last_name',
    'contact_email',
    'contact_phone',
    'use_case_description',
    'sample_message',
] as const;

/**
 * Company → Messaging: SMS mode, quiet hours, review requests, templates, SMS number and US registration.
 */
export default function MessagingSettings({
    settings,
    modes,
    templates,
    account,
    registration,
}: {
    settings: Settings;
    modes: (Option & { hint: string })[];
    templates: Template[];
    account: { configured: boolean; phone_number: string | null };
    registration: Registration;
}) {
    const t = useTrans();
    const form = useForm({
        ...settings,
        templates: Object.fromEntries(
            templates.map((tpl) => [tpl.kind, tpl.text]),
        ),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(update().url, { preserveScroll: true });
    };

    const mode = modes.find((m) => m.value === form.data.sms_mode);

    return (
        <>
            <Head title={t('messages.settings_title')} />

            <div className="max-w-2xl space-y-8 p-4">
                <PageHeader
                    title={t('messages.settings_title')}
                    description={t('messages.settings_description')}
                />

                <form onSubmit={submit} className="space-y-8">
                    <section className="grid gap-4">
                        <FormField
                            id="sms_mode"
                            label={t('messages.mode')}
                            hint={mode?.hint}
                            error={errors.sms_mode}
                        >
                            <NativeSelect
                                id="sms_mode"
                                value={form.data.sms_mode}
                                onChange={(e) =>
                                    form.setData('sms_mode', e.target.value)
                                }
                            >
                                {modes.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                id="quiet_hours_start"
                                label={t('messages.quiet_from')}
                                error={errors.quiet_hours_start}
                            >
                                <Input
                                    id="quiet_hours_start"
                                    type="time"
                                    value={form.data.quiet_hours_start}
                                    onChange={(e) =>
                                        form.setData(
                                            'quiet_hours_start',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="quiet_hours_end"
                                label={t('messages.quiet_until')}
                                error={errors.quiet_hours_end}
                            >
                                <Input
                                    id="quiet_hours_end"
                                    type="time"
                                    value={form.data.quiet_hours_end}
                                    onChange={(e) =>
                                        form.setData(
                                            'quiet_hours_end',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        </div>
                        <p className="-mt-2 text-xs text-muted-foreground">
                            {t('messages.quiet_hint')}
                        </p>
                    </section>

                    <section className="grid gap-4">
                        <h2 className="text-base font-medium">
                            {t('reviews.settings')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('reviews.rules')}
                        </p>
                        <label className="flex min-h-10 items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.review_requests_default}
                                onCheckedChange={(c) =>
                                    form.setData(
                                        'review_requests_default',
                                        c === true,
                                    )
                                }
                            />
                            {t(
                                'reviews.fields_settings.review_requests_default',
                            )}
                        </label>
                        <div className="grid grid-cols-2 gap-4">
                            {(
                                [
                                    'review_request_delay_hours',
                                    'review_request_cooldown_days',
                                ] as const
                            ).map((field) => (
                                <FormField
                                    key={field}
                                    id={field}
                                    label={t(
                                        `reviews.fields_settings.${field}`,
                                    )}
                                    error={errors[field]}
                                >
                                    <Input
                                        id={field}
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        value={form.data[field]}
                                        onChange={(e) =>
                                            form.setData(
                                                field,
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </FormField>
                            ))}
                        </div>
                    </section>

                    <section className="grid gap-4">
                        <h2 className="text-base font-medium">
                            {t('messages.templates_title')}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {t('messages.placeholders')}
                        </p>
                        {templates.map((tpl) => (
                            <FormField
                                key={tpl.kind}
                                id={`template-${tpl.kind}`}
                                label={tpl.label}
                                hint={
                                    tpl.kind === 'review_request'
                                        ? t('messages.review_template_hint')
                                        : undefined
                                }
                                error={errors[`templates.${tpl.kind}`]}
                            >
                                <Textarea
                                    id={`template-${tpl.kind}`}
                                    rows={3}
                                    placeholder={tpl.default}
                                    value={form.data.templates[tpl.kind] ?? ''}
                                    onChange={(e) =>
                                        form.setData('templates', {
                                            ...form.data.templates,
                                            [tpl.kind]: e.target.value,
                                        })
                                    }
                                />
                            </FormField>
                        ))}
                    </section>

                    <Button
                        type="submit"
                        className="w-full sm:w-auto"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </Button>
                </form>

                {settings.sms_mode === 'automatic' && (
                    <section className="space-y-3 rounded-lg border p-4">
                        <h2 className="text-base font-medium">
                            {t('messages.account.title')}
                        </h2>
                        {account.phone_number ? (
                            <p className="flex items-center gap-2 text-sm">
                                <Phone className="size-4" />
                                {t('messages.account.number', {
                                    number: account.phone_number,
                                })}
                            </p>
                        ) : account.configured ? (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {t('messages.account.none')}
                                </p>
                                <Button
                                    onClick={() =>
                                        router.post(
                                            provision().url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {t('messages.account.set_up')}
                                </Button>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('messages.account.not_configured')}
                            </p>
                        )}
                    </section>
                )}

                {registration && (
                    <RegistrationForm registration={registration} />
                )}
            </div>
        </>
    );
}

function RegistrationForm({
    registration,
}: {
    registration: NonNullable<Registration>;
}) {
    const t = useTrans();
    const form = useForm({ business: registration.business, submit: false });
    const errors = form.errors as Record<string, string | undefined>;
    const locked = registration.status === 'approved';

    const save = (submit: boolean) => {
        form.transform((data) => ({ ...data, submit }));
        form.put(registrationRoute().url, { preserveScroll: true });
    };

    const set = (field: string, value: string) =>
        form.setData('business', { ...form.data.business, [field]: value });

    return (
        <section className="space-y-4 rounded-lg border p-4">
            <h2 className="text-base font-medium">
                {t('messages.registration.title')}
            </h2>
            <p className="text-sm text-muted-foreground">
                {t('messages.registration.description')}
            </p>
            <p className="flex items-center gap-2 text-sm font-medium">
                {registration.status === 'approved' && (
                    <CheckCircle2 className="size-4 text-green-600" />
                )}
                {t('messages.registration.status', {
                    status: registration.status_label,
                })}
            </p>
            {registration.rejection_reason && (
                <p className="text-sm text-destructive">
                    {t('messages.registration.rejected_reason', {
                        reason: registration.rejection_reason,
                    })}
                </p>
            )}

            <fieldset disabled={locked} className="grid gap-3 sm:grid-cols-2">
                {BUSINESS_FIELDS.map((field) => {
                    const id = `business-${field}`;
                    const wide = [
                        'use_case_description',
                        'sample_message',
                        'legal_name',
                        'street',
                    ].includes(field);

                    return (
                        <FormField
                            key={field}
                            id={id}
                            label={t(`messages.registration.fields.${field}`)}
                            error={errors[`business.${field}`]}
                            className={wide ? 'sm:col-span-2' : undefined}
                        >
                            {field === 'business_type' ? (
                                <NativeSelect
                                    id={id}
                                    value={form.data.business[field] ?? ''}
                                    onChange={(e) => set(field, e.target.value)}
                                >
                                    <option value="" />
                                    {registration.business_types.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            ) : field === 'use_case_description' ||
                              field === 'sample_message' ? (
                                <Textarea
                                    id={id}
                                    rows={3}
                                    value={form.data.business[field] ?? ''}
                                    onChange={(e) => set(field, e.target.value)}
                                />
                            ) : (
                                <Input
                                    id={id}
                                    value={form.data.business[field] ?? ''}
                                    onChange={(e) => set(field, e.target.value)}
                                />
                            )}
                        </FormField>
                    );
                })}
            </fieldset>
            <InputError message={errors.business} />
            {!locked && (
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => save(false)}
                    >
                        {t('messages.registration.save')}
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() => save(true)}
                    >
                        {t('messages.registration.submit')}
                    </Button>
                </div>
            )}
        </section>
    );
}

MessagingSettings.layout = {
    breadcrumbs: [{ title: 'messages.settings_title', href: edit() }],
};
