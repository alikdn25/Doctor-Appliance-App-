import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { smsRegistration } from '@/routes/admin/companies';

export type AdminSms = {
    mode: string;
    number: string | null;
    registration: {
        status: string;
        business: Record<string, string | null>;
        brand_registration_sid: string | null;
        messaging_service_sid: string | null;
        campaign_sid: string | null;
        rejection_reason: string | null;
    } | null;
};

/**
 * Super-admin: the company's SMS mode/number and its A2P 10DLC registration (details, IDs, status).
 */
export function AdminSmsSection({
    companyId,
    sms,
}: {
    companyId: number;
    sms: AdminSms;
}) {
    const t = useTrans();
    const r = sms.registration;
    const form = useForm({
        status: r?.status === 'draft' || !r ? 'submitted' : r.status,
        brand_registration_sid: r?.brand_registration_sid ?? '',
        messaging_service_sid: r?.messaging_service_sid ?? '',
        campaign_sid: r?.campaign_sid ?? '',
        rejection_reason: r?.rejection_reason ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(smsRegistration(companyId).url, { preserveScroll: true });
    };

    const text = (
        field:
            | 'brand_registration_sid'
            | 'messaging_service_sid'
            | 'campaign_sid'
            | 'rejection_reason',
    ) => (
        <FormField id={`sms-${field}`} label={field} error={form.errors[field]}>
            <Input
                id={`sms-${field}`}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
            />
        </FormField>
    );

    return (
        <section className="space-y-3">
            <h2 className="text-base font-medium">{t('admin.sms.title')}</h2>
            <p className="text-sm text-muted-foreground">
                {t('admin.sms.mode', { mode: sms.mode })}
                {sms.number &&
                    ` · ${t('admin.sms.number', { number: sms.number })}`}
            </p>
            <h3 className="text-sm font-medium">
                {t('admin.sms.registration')}
            </h3>
            {!r ? (
                <p className="text-sm text-muted-foreground">
                    {t('admin.sms.none')}
                </p>
            ) : (
                <>
                    <dl className="grid gap-x-4 gap-y-1 rounded-lg border p-3 text-sm sm:grid-cols-2">
                        {Object.entries(r.business).map(([key, value]) => (
                            <div key={key} className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    {t(`messages.registration.fields.${key}`)}
                                </dt>
                                <dd className="break-words">{value || '—'}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="text-xs text-muted-foreground">
                        {t('admin.sms.hint')}
                    </p>
                    <form
                        onSubmit={submit}
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        <FormField
                            id="sms-status"
                            label={t('admin.fields.status')}
                            error={form.errors.status}
                        >
                            <NativeSelect
                                id="sms-status"
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData('status', e.target.value)
                                }
                            >
                                {['submitted', 'approved', 'rejected'].map(
                                    (s) => (
                                        <option key={s} value={s}>
                                            {t(
                                                `messages.registration.statuses.${s}`,
                                            )}
                                        </option>
                                    ),
                                )}
                            </NativeSelect>
                        </FormField>
                        {text('brand_registration_sid')}
                        {text('messaging_service_sid')}
                        {text('campaign_sid')}
                        {text('rejection_reason')}
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="sm:col-span-2 sm:w-fit"
                        >
                            {t('admin.sms.save')}
                        </Button>
                    </form>
                </>
            )}
        </section>
    );
}
