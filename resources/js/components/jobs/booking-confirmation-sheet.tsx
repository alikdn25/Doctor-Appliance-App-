import { Link, router } from '@inertiajs/react';
import { CheckCircle2, MessageSquare, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';
import {
    send as sendRoute,
    show as showRoute,
} from '@/routes/jobs/booking-confirmation';
import { opened } from '@/routes/jobs/messages';

/** How the confirmation goes, as the company's SMS setting says (null channel = it does not go). */
export type ConfirmationPlan = {
    channel: 'sms' | 'phone' | 'email' | null;
    to: string | null;
    reason: string | null;
};

/** What happened to it after Finish booking. */
export type ConfirmationSent = ConfirmationPlan & {
    body: string;
    status: string | null;
    send_after: string | null;
};

type Booked = {
    job_id: number;
    number: number;
    message: string;
    url: string;
    draft: boolean;
    sent: ConfirmationSent | null;
};

/** From technician's phone: the phone's messages app opens with the text ready (within the tap). */
function textFromPhone(jobId: number, to: string, body: string) {
    window.location.href = smsUrl(to, body);
    router.post(
        opened(jobId).url,
        { kind: 'booking_confirmation', to, body },
        { preserveScroll: true, preserveState: true },
    );
}

/** "By text to (604) 555-0142", "By email to …" or "Not sent: …". */
function usePlanLine() {
    const t = useTrans();
    const phone = usePhone();

    return (plan: ConfirmationPlan) => {
        const to =
            plan.channel === 'email' ? (plan.to ?? '') : phone(plan.to ?? '');

        switch (plan.channel) {
            case 'sms':
                return t('jobs.confirmation.will_sms', { to });
            case 'phone':
                return t('jobs.confirmation.will_phone', { to });
            case 'email':
                return plan.reason
                    ? t('jobs.confirmation.will_email_because', {
                          to,
                          reason: plan.reason,
                      })
                    : t('jobs.confirmation.will_email', { to });
            default:
                return t('jobs.confirmation.wont_send', {
                    reason: plan.reason ?? '',
                });
        }
    };
}

/**
 * After a booking is saved (the "booking_confirmation" flash), on whatever screen it lands: "Booked #1005 ·
 * Tomorrow 9–11" and what happened to the confirmation — texted, emailed, queued for the end of quiet hours, not sent
 * (and why), or "Text from my phone" when the company texts from the technician's phone. A draft says nothing went.
 */
export function BookingConfirmationPrompt() {
    const t = useTrans();
    const phone = usePhone();
    const [booked, setBooked] = useState<Booked | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const data = (event as CustomEvent).detail?.flash
                    ?.booking_confirmation as Booked | undefined;
                if (data) setBooked(data);
            }),
        [],
    );

    const sent = booked?.sent ?? null;
    const failed =
        sent !== null && (sent.channel === null || sent.status === 'blocked');
    const line =
        booked === null
            ? ''
            : booked.draft || sent === null
              ? t('jobs.confirmation.draft_not_sent')
              : failed
                ? t('jobs.confirmation.wont_send', {
                      reason: sent.reason ?? '',
                  })
                : sent.channel === 'email'
                  ? t('jobs.confirmation.sent_email', { to: sent.to ?? '' })
                  : sent.channel === 'phone'
                    ? t('jobs.confirmation.will_phone', {
                          to: phone(sent.to ?? ''),
                      })
                    : sent.send_after
                      ? t('jobs.confirmation.queued_sms', {
                            to: phone(sent.to ?? ''),
                            time: sent.send_after,
                        })
                      : t('jobs.confirmation.sent_sms', {
                            to: phone(sent.to ?? ''),
                        });
    const close = () => setBooked(null);

    return (
        <Sheet open={booked !== null} onOpenChange={(open) => !open && close()}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[85dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="booking-confirmation"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{booked?.message}</SheetTitle>
                    <SheetDescription
                        className="flex items-start gap-2"
                        data-test="booking-confirmation-result"
                    >
                        {failed || booked?.draft ? (
                            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                        ) : (
                            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" />
                        )}
                        <span>{line}</span>
                    </SheetDescription>
                </SheetHeader>
                {booked && sent?.channel === 'phone' && sent.to && (
                    <Button
                        type="button"
                        size="lg"
                        onClick={() => {
                            textFromPhone(booked.job_id, sent.to!, sent.body);
                            close();
                        }}
                        data-test="confirmation-phone"
                    >
                        <MessageSquare />{' '}
                        {t('jobs.confirmation.text_from_phone')}
                    </Button>
                )}
                <div className="grid grid-cols-2 gap-2">
                    <Button type="button" variant="ghost" onClick={close}>
                        {t('ui.done')}
                    </Button>
                    {booked && (
                        <Button asChild variant="tertiary">
                            <Link
                                href={booked.url}
                                onClick={close}
                                data-test="confirmation-open-job"
                            >
                                {t('jobs.quick.open_job')}
                            </Link>
                        </Button>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

/**
 * "Resend confirmation" from the job's "…" menu: the text and how it goes, then Yes / No. Yes sends it as the
 * company's SMS setting says.
 */
export function ConfirmationSendSheet({
    jobId,
    open,
    onOpenChange,
    title,
    hint,
}: {
    jobId: number | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    hint?: string;
}) {
    const t = useTrans();
    const planLine = usePlanLine();
    const [preview, setPreview] = useState<
        (ConfirmationPlan & { body: string }) | null
    >(null);
    const [sending, setSending] = useState(false);

    useEffect(() => {
        if (!open || jobId === null) return;
        setPreview(null);
        const controller = new AbortController();
        fetch(showRoute(jobId).url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => setPreview(data))
            .catch(() => undefined);

        return () => controller.abort();
    }, [open, jobId]);

    const send = () => {
        if (jobId === null || preview === null) return;
        if (preview.channel === 'phone' && preview.to) {
            textFromPhone(jobId, preview.to, preview.body);
            onOpenChange(false);

            return;
        }
        setSending(true);
        router.post(
            sendRoute(jobId).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[85dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="confirmation-send"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>
                        {hint ?? t('jobs.confirmation.title')}
                    </SheetDescription>
                </SheetHeader>
                {preview === null ? (
                    <div className="flex justify-center p-6">
                        <Spinner />
                    </div>
                ) : (
                    <>
                        <p className="da-card p-3 text-sm whitespace-pre-line">
                            {preview.body}
                        </p>
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="confirmation-plan"
                        >
                            {planLine(preview)}
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                onClick={() => onOpenChange(false)}
                                data-test="confirmation-no"
                            >
                                {t('jobs.confirmation.no')}
                            </Button>
                            <Button
                                type="button"
                                size="lg"
                                disabled={preview.channel === null}
                                loading={sending}
                                onClick={send}
                                data-test="confirmation-yes"
                            >
                                {t('jobs.confirmation.yes')}
                            </Button>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
