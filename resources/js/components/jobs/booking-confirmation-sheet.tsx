import { Link, router } from '@inertiajs/react';
import { Mail, MessageSquare } from 'lucide-react';
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
import { opened } from '@/routes/jobs/messages';
import {
    send as sendRoute,
    show as showRoute,
} from '@/routes/jobs/booking-confirmation';

type Preview = {
    body: string;
    email: string | null;
    sms: { mode: string; to: string | null; blocked: string | null };
};

/**
 * Send Booking Confirmation: the day, window and address to the customer (the one who pays), by Email or SMS.
 * From technician's phone the SMS opens the phone's messages app with the text ready.
 */
export function BookingConfirmationSheet({
    jobId,
    open,
    onOpenChange,
    title,
    jobUrl,
}: {
    jobId: number | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** E.g. "Booked #1005. Send the customer a confirmation?" right after booking. */
    title?: string;
    /** Right after booking: Open job next to Not now. */
    jobUrl?: string;
}) {
    const t = useTrans();
    const phoneText = usePhone();
    const [preview, setPreview] = useState<Preview | null>(null);
    const [sending, setSending] = useState<'email' | 'sms' | null>(null);

    useEffect(() => {
        if (!open || jobId === null) return;
        setPreview(null);
        const controller = new AbortController();
        fetch(showRoute(jobId).url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: Preview | null) => setPreview(data))
            .catch(() => undefined);

        return () => controller.abort();
    }, [open, jobId]);

    const send = (channel: 'email' | 'sms') => {
        if (jobId === null || preview === null) return;
        if (channel === 'sms' && preview.sms.mode === 'technician_phone') {
            const to = preview.sms.to ?? '';
            window.location.href = smsUrl(to, preview.body);
            router.post(
                opened(jobId).url,
                { kind: 'booking_confirmation', to, body: preview.body },
                { preserveScroll: true, preserveState: true },
            );
            onOpenChange(false);

            return;
        }
        setSending(channel);
        router.post(
            sendRoute(jobId).url,
            { channel },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSending(null),
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[85dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="booking-confirmation"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>
                        {title ?? t('jobs.confirmation.title')}
                    </SheetTitle>
                    <SheetDescription>
                        {t('jobs.confirmation.send')}
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
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={!preview.email}
                                loading={sending === 'email'}
                                onClick={() => send('email')}
                                data-test="confirmation-email"
                            >
                                <Mail /> {t('jobs.confirmation.email')}
                            </Button>
                            <Button
                                type="button"
                                disabled={!!preview.sms.blocked}
                                loading={sending === 'sms'}
                                onClick={() => send('sms')}
                                data-test="confirmation-sms"
                            >
                                <MessageSquare /> {t('jobs.confirmation.sms')}
                            </Button>
                        </div>
                        <ul className="space-y-1 text-xs text-muted-foreground">
                            <li className="break-all">
                                {preview.email ??
                                    t('jobs.confirmation.no_email')}
                            </li>
                            <li>
                                {preview.sms.blocked ??
                                    phoneText(preview.sms.to)}
                            </li>
                        </ul>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                className={jobUrl ? undefined : 'col-span-2'}
                                onClick={() => onOpenChange(false)}
                            >
                                {t('jobs.confirmation.not_now')}
                            </Button>
                            {jobUrl && (
                                <Button asChild variant="tertiary">
                                    <Link
                                        href={jobUrl}
                                        onClick={() => onOpenChange(false)}
                                        data-test="confirmation-open-job"
                                    >
                                        {t('jobs.quick.open_job')}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

/**
 * Opens the confirmation right after a booking is saved (the "booking_confirmation" flash), on whatever screen the
 * booking lands.
 */
type Booked = { job_id: number; number: number; message: string; url: string };

export function BookingConfirmationPrompt() {
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

    return (
        <BookingConfirmationSheet
            jobId={booked?.job_id ?? null}
            open={booked !== null}
            onOpenChange={(open) => !open && setBooked(null)}
            title={booked?.message}
            jobUrl={booked?.url}
        />
    );
}
