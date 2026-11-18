import { Head, router, useForm } from '@inertiajs/react';
import { CloudUpload } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTrans } from '@/lib/i18n';
import { connect, disconnect } from '@/routes/google-drive';
import { edit, update } from '@/routes/company/morning';

type Settings = {
    morning_sms_enabled: boolean;
    morning_sms_phone: string;
    morning_sms_time: string;
};

type Drive = {
    available: boolean;
    connected: boolean;
    email: string | null;
    last: { status: 'ok' | 'failed'; at: string; error: string | null } | null;
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
 * Company → Morning SMS & backup (owner's task of November 19): the morning SMS to the owner's own phone and the
 * nightly copy of customers and next week's jobs to Google Drive.
 */
export default function Morning({
    settings,
    preview,
    drive,
    folder,
}: {
    settings: Settings;
    preview: string;
    drive: Drive;
    folder: string;
}) {
    const t = useTrans();
    const form = useForm<Settings>(settings);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(update().url, { preserveScroll: true });
    };
    const when = (iso: string) =>
        new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });

    return (
        <>
            <Head title={t('automation.morning_sms.title')} />
            <ScreenHeader title={t('automation.morning_sms.title')} />

            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6">
                <p className="text-sm text-muted-foreground">
                    {t('automation.morning_sms.description')}
                </p>

                <form onSubmit={submit} className="space-y-4">
                    <Card
                        title={t('automation.morning_sms.sms_title')}
                        hint={t('automation.morning_sms.sms_hint')}
                    >
                        <label className="flex min-h-11 items-center gap-3 text-sm font-medium">
                            <Checkbox
                                checked={form.data.morning_sms_enabled}
                                onCheckedChange={(c) =>
                                    form.setData(
                                        'morning_sms_enabled',
                                        c === true,
                                    )
                                }
                                data-test="morning-sms-enabled"
                            />
                            {t('automation.morning_sms.enabled')}
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="morning-phone">
                                    {t('automation.morning_sms.phone')}
                                </Label>
                                <Input
                                    id="morning-phone"
                                    type="tel"
                                    inputMode="tel"
                                    autoComplete="tel"
                                    value={form.data.morning_sms_phone}
                                    onChange={(e) =>
                                        form.setData(
                                            'morning_sms_phone',
                                            e.target.value,
                                        )
                                    }
                                    data-test="morning-sms-phone"
                                />
                                <InputError
                                    message={form.errors.morning_sms_phone}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="morning-time">
                                    {t('automation.morning_sms.time')}
                                </Label>
                                <Input
                                    id="morning-time"
                                    type="time"
                                    value={form.data.morning_sms_time}
                                    onChange={(e) =>
                                        form.setData(
                                            'morning_sms_time',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.morning_sms_time}
                                />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <p className="text-sm font-medium">
                                {t('automation.morning_sms.preview')}
                            </p>
                            <pre
                                className="rounded-xl bg-muted p-3 text-sm whitespace-pre-wrap"
                                data-test="morning-sms-preview"
                            >
                                {preview}
                            </pre>
                        </div>
                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            loading={form.processing}
                        >
                            {t('common.save')}
                        </Button>
                    </Card>
                </form>

                <Card
                    title={t('automation.backup.title')}
                    hint={t('automation.backup.hint', { folder })}
                >
                    {!drive.available ? (
                        <p className="text-sm text-amber-800">
                            {t('automation.backup.not_available')}
                        </p>
                    ) : drive.connected ? (
                        <div className="space-y-2">
                            <p className="text-sm font-medium">
                                {drive.email
                                    ? t('automation.backup.connected_as', {
                                          email: drive.email,
                                      })
                                    : t('automation.backup.connected_plain')}
                            </p>
                            {drive.last && (
                                <p
                                    className={
                                        drive.last.status === 'ok'
                                            ? 'text-sm text-muted-foreground'
                                            : 'text-sm text-red-700'
                                    }
                                >
                                    {drive.last.status === 'ok'
                                        ? t('automation.backup.last_ok', {
                                              at: when(drive.last.at),
                                          })
                                        : t('automation.backup.last_failed', {
                                              at: when(drive.last.at),
                                              error: drive.last.error ?? '',
                                          })}
                                </p>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.delete(disconnect().url, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {t('automation.backup.disconnect')}
                            </Button>
                        </div>
                    ) : (
                        <Button
                            type="button"
                            size="lg"
                            className="w-full"
                            onClick={() => router.post(connect().url)}
                            data-test="connect-drive"
                        >
                            <CloudUpload className="size-5" />
                            {t('automation.backup.connect')}
                        </Button>
                    )}
                </Card>
            </div>
        </>
    );
}

Morning.layout = {
    breadcrumbs: [{ title: 'automation.morning_sms.title', href: edit() }],
};
