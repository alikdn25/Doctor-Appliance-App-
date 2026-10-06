import { router, useForm } from '@inertiajs/react';
import { MessageSquare, Send, Star } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { MessageHistory } from '@/components/messaging/message-history';
import type { JobMessaging } from '@/components/messaging/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { askForReview, sms } from '@/routes/jobs';
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
 * The job's texting tools: "Ask for a review", the review request button (technician's phone mode),
 * an SMS dialog (Automatic mode) and the message history.
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

            <div className="rounded-lg border p-3 text-sm">
                <label className="flex min-h-10 items-center gap-2">
                    <Checkbox
                        checked={messaging.review.ask}
                        disabled={!canWork}
                        onCheckedChange={(c) =>
                            router.put(
                                askForReview(jobId).url,
                                { ask: c === true },
                                { preserveScroll: true },
                            )
                        }
                    />
                    <Star className="size-4" />
                    {t('reviews.ask_for_review')}
                </label>
                <p className="text-xs text-muted-foreground">
                    {messaging.review.status ?? t('reviews.ask_hint')}
                </p>
                {messaging.review.ask && !messaging.review.has_profile && (
                    <p className="mt-1 text-xs font-medium text-amber-800">
                        {t('reviews.missing_profile')}
                    </p>
                )}
                {messaging.mode === 'technician_phone' &&
                    canWork &&
                    canText &&
                    messaging.review.has_profile && (
                        <Button
                            variant="outline"
                            className="mt-2 h-11 w-full"
                            onClick={() =>
                                openOnPhone(
                                    jobId,
                                    'review_request',
                                    messaging.phone!,
                                    messaging.texts.review_request,
                                )
                            }
                        >
                            <Star /> {t('reviews.send_request')}
                        </Button>
                    )}
            </div>

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
