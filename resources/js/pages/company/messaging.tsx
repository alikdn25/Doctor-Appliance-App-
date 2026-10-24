import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Phone } from 'lucide-react';
import type { FormEvent } from 'react';
import { formatMoney } from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { TemplateEditor } from '@/components/messaging/template-editor';
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
 * Company → Messaging: SMS mode, quiet hours, templates, SMS number and US registration.
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
    const { auth } = usePage().props;
    const companyName = auth.company?.name ?? '';
    const locale = auth.company?.locale ?? 'en-US';
    const currency = auth.company?.currency ?? 'USD';
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

    // Sample details for the template preview.
    const sample = {
        customer_first_name: 'Jane',
        customer_name: 'Jane Cooper',
        brand: companyName,
        company: companyName,
        tech_name: 'Tom',
        visit_date: new Intl.DateTimeFormat(locale, {
            weekday: 'long',
            month: 'short',
            day: 'numeric',
            timeZone: 'UTC',
        }).format(new Date('2026-10-20T12:00:00Z')),
        arrival_window: new Intl.DateTimeFormat(locale, {
            hour: 'numeric',
            timeZone: 'UTC',
        }).formatRange(
            new Date('2026-10-20T09:00:00Z'),
            new Date('2026-10-20T11:00:00Z'),
        ),
        address: '123 Main St, Springfield',
        number: 'INV-12',
        amount: formatMoney(10640, currency, locale),
        link: 'https://example.com/d/…',
        review_link: 'https://g.page/r/…',
    };

    return (
        <>
            <Head title={t('messages.settings_title')} />

            <div className="max-w-2xl space-y-8 p-4">
                <PageHeader
                    title={t('messages.settings_title')}
                    description={t('messages.settings_description')}
                />

                <form onSubmit={submit} className="space-y-8">
                    <section className="grid gap-3">
                        <h2 className="text-base font-medium">
                            {t('messages.mode')}
                        </h2>
                        <div
                            role="radiogroup"
                            aria-label={t('messages.mode')}
                            className="grid gap-2"
                            data-test="sms-modes"
                        >
                            {modes.map((m) => {
                                const on = form.data.sms_mode === m.value;

                                return (
                                    <button
                                        key={m.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={on}
                                        onClick={() =>
                                            form.setData('sms_mode', m.value)
                                        }
                                        className={
                                            on
                                                ? 'da-raised da-press rounded-2xl p-4 text-left ring-2 ring-primary'
                                                : 'da-card da-press rounded-2xl p-4 text-left'
                                        }
                                    >
                                        <span className="block font-semibold">
                                            {m.label}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {m.hint}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        {errors.sms_mode && (
                            <p className="text-sm text-danger">
                                {errors.sms_mode}
                            </p>
                        )}
                    </section>

                    {/* Quiet hours matter only when the app sends texts by itself. */}
                    {form.data.sms_mode === 'automatic' && (
                        <section className="grid gap-3" data-test="quiet-hours">
                            <h2 className="text-base font-medium">
                                {t('messages.quiet_title')}
                            </h2>
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
                            <p className="-mt-1 text-xs text-muted-foreground">
                                {t('messages.quiet_hint')}
                            </p>
                        </section>
                    )}

                    <section className="grid gap-4">
                        <h2 className="text-base font-medium">
                            {t('messages.templates_title')}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {t('messages.placeholders')}
                        </p>
                        {templates.map((tpl) => (
                            <TemplateEditor
                                key={tpl.kind}
                                id={`template-${tpl.kind}`}
                                label={tpl.label}
                                hint={
                                    tpl.kind === 'review_request'
                                        ? t('messages.review_template_hint')
                                        : undefined
                                }
                                error={errors[`templates.${tpl.kind}`]}
                                value={form.data.templates[tpl.kind] ?? ''}
                                fallback={tpl.default}
                                sample={sample}
                                onChange={(text) =>
                                    form.setData('templates', {
                                        ...form.data.templates,
                                        [tpl.kind]: text,
                                    })
                                }
                            />
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
