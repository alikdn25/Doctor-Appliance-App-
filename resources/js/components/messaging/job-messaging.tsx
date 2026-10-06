import { router, useForm } from '@inertiajs/react';
import { MessageSquare, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { MessageHistory } from '@/components/messaging/message-history';
import type { JobMessaging } from '@/components/messaging/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { smsUrl } from '@/lib/sms';
import { sms } from '@/routes/jobs';
import { opened } from '@/routes/jobs/messages';

/**
 * Opens the messages app on the technician's phone with the text ready and records it on the job.
 * The record is posted in the background; the phone switches to the messages app right away.
 */
/** Opens the phone's messages app with the text ready, without recording it (the caller records it). */
export function openSmsApp(to: string, body: string): void {
    window.location.href = smsUrl(to, body);
}

export function openOnPhone(
    jobId: number,
    kind: string,
    to: string,
    body: string,
): void {
    router.post(
        opened(jobId).url,
        { kind, to, body },
        { preserveScroll: true, preserveState: true },
    );
    window.location.href = smsUrl(to, body);
}

/**
 * The job's texting tools: an SMS dialog (Automatic mode) and the message history.
 */
export function JobMessagingSection({
    jobId,
    messaging,
    canWork,
}: {
    jobId: number;
    messaging: JobMessaging;
    canWork: boolean;
}) {
    const t = useTrans();
    const [open, setOpen] = useState(false);
    const form = useForm({ body: messaging.texts.general });
    const canText = messaging.phone !== null && !messaging.opted_out;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(sms(jobId).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <section className="space-y-3">
            <h2 className="text-base font-medium">{t('messages.title')}</h2>

            {messaging.opted_out && (
                <p className="text-sm text-destructive">
                    {t('messages.opted_out')}
                </p>
            )}
            {messaging.mode === 'automatic' && messaging.sms_blocked && (
                <p className="text-sm text-muted-foreground">
                    {t('messages.sms_blocked', {
                        reason: messaging.sms_blocked,
                    })}
                </p>
            )}

            {messaging.mode === 'automatic' && canWork && canText && (
                <Button
                    variant="outline"
                    className="h-11 w-full"
                    disabled={messaging.sms_blocked !== null}
                    onClick={() => setOpen(true)}
                >
                    <MessageSquare /> {t('messages.send_sms')}
                </Button>
            )}

            <MessageHistory messages={messaging.messages} />

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('messages.send_sms')}</DialogTitle>
                        <DialogDescription>{messaging.phone}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <Textarea
                            rows={5}
                            maxLength={1000}
                            aria-label={t('documents.message')}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                        {form.errors.body && (
                            <p className="text-sm text-destructive">
                                {form.errors.body}
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="h-11 w-full"
                            disabled={form.processing}
                        >
                            <Send /> {t('messages.send_sms')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
