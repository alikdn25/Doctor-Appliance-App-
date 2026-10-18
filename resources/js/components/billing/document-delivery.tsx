import { router, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Eye,
    Link2,
    Mail,
    MessageSquare,
    Send,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ReviewPromptDialog } from '@/components/billing/review-prompt';
import { FormField } from '@/components/form-field';
import type { DocumentSms, ReviewPrompt } from '@/components/messaging/types';
import { ActionSheet } from '@/components/ui/action-sheet';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { formatPhone } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';

export type Delivery = {
    pdf_url: string;
    send_url: string;
    public_url: string | null;
    /** Makes the customer's link on first use and returns it ("Copy link"). */
    link_url: string;
    /** "Send receipt" once money was taken (invoices). */
    receipt_url: string | null;
    can_send: boolean;
    sent_at: string | null;
    sent_to: string | null;
    viewed_at: string | null;
    email: string;
    message: string;
};

/**
 * Sending an estimate or invoice: by email (dialog with the message), by SMS (from the company number, or opened in
 * the phone's messages app), "Copy link", and "Send receipt" after payment. After an invoice goes out,
 * "Send Google Review request?" is asked (reviewPrompt). The screen places the buttons; `dialogs` must be rendered.
 */
export function useDocumentDelivery({
    delivery,
    kindLabel,
    number,
    sms = null,
    reviewPrompt = null,
}: {
    delivery: Delivery;
    kindLabel: string;
    number: string;
    sms?: DocumentSms;
    reviewPrompt?: ReviewPrompt;
}) {
    const t = useTrans();
    const [emailOpen, setEmailOpen] = useState(false);
    const [receiptOpen, setReceiptOpen] = useState(false);
    const [reviewOpen, setReviewOpen] = useState(false);
    const [smsOpen, setSmsOpen] = useState(false);
    const [smsSending, setSmsSending] = useState(false);
    const { auth } = usePage().props;
    // After "Send by SMS": asked only when the text really went out (a blocked SMS leaves "sent" unchanged).
    const askForReview = (page: { props: Record<string, unknown> }) => {
        const sent = (page.props.delivery as Delivery | undefined)?.sent_at;

        if (reviewPrompt && sent && sent !== delivery.sent_at) {
            setReviewOpen(true);
        }
    };
    const form = useForm({ email: delivery.email, message: delivery.message });
    const receipt = useForm({ email: delivery.email });

    useEffect(() => {
        if (emailOpen) {
            form.clearErrors();
            form.setData({ email: delivery.email, message: delivery.message });
        }
        // Reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [emailOpen]);

    useEffect(() => {
        if (receiptOpen) {
            receipt.clearErrors();
            receipt.setData({ email: delivery.email });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [receiptOpen]);

    // "Send by SMS": sent by the app (Automatic) after "Send SMS?" is confirmed, or opened in the phone's messages
    // app (technician's phone), where the technician presses Send himself.
    const sendSms = () => {
        if (!sms || !sms.phone) {
            return;
        }

        if (sms.mode === 'automatic') {
            setSmsOpen(true);

            return;
        }

        router.post(
            sms.opened_url,
            { kind: sms.kind, to: sms.phone, body: sms.text },
            { preserveScroll: true, preserveState: true },
        );
        // The prompt waits on the page for when the technician comes back from the messages app.
        if (reviewPrompt) {
            setReviewOpen(true);
        }
        window.location.href = smsUrl(sms.phone, sms.text);
    };

    const confirmSms = () => {
        if (!sms) {
            return;
        }

        router.post(
            sms.url,
            {},
            {
                preserveScroll: true,
                onStart: () => setSmsSending(true),
                onFinish: () => setSmsSending(false),
                onSuccess: (page) => {
                    setSmsOpen(false);
                    askForReview(page);
                },
            },
        );
    };

    const copyLink = async () => {
        let url = delivery.public_url;

        try {
            if (!url) {
                const response = await sendJson(delivery.link_url, 'POST', {});
                url = ((await response.json()) as { url: string }).url;
            }

            await navigator.clipboard.writeText(url);
            toast.success(t('documents.link_copied'));
        } catch {
            if (url) {
                window.prompt(t('documents.copy_link'), url);
            } else {
                toast.error(t('documents.link_failed'));
            }
        }
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(delivery.send_url, {
            preserveScroll: true,
            onSuccess: () => {
                setEmailOpen(false);
                setReviewOpen(reviewPrompt !== null);
            },
        });
    };

    const submitReceipt = (e: FormEvent) => {
        e.preventDefault();

        if (!delivery.receipt_url) {
            return;
        }

        receipt.post(delivery.receipt_url, {
            preserveScroll: true,
            onSuccess: () => setReceiptOpen(false),
        });
    };

    const canSms = delivery.can_send && sms !== null;

    const dialogs: ReactNode = (
        <>
            <Dialog open={emailOpen} onOpenChange={setEmailOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('documents.send_title', {
                                kind: kindLabel,
                                number,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('documents.send_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <FormField
                            id="send-email"
                            label={t('documents.to')}
                            hint={
                                delivery.email
                                    ? undefined
                                    : t('documents.no_email')
                            }
                            error={form.errors.email}
                        >
                            <Input
                                id="send-email"
                                type="email"
                                inputMode="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            id="send-message"
                            label={t('documents.message')}
                            error={form.errors.message}
                        >
                            <Textarea
                                id="send-message"
                                rows={8}
                                value={form.data.message}
                                onChange={(e) =>
                                    form.setData('message', e.target.value)
                                }
                            />
                        </FormField>
                        <Button
                            type="submit"
                            className="h-11 w-full"
                            disabled={form.processing}
                        >
                            <Send /> {t('documents.send')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>

            {delivery.receipt_url && (
                <Dialog open={receiptOpen} onOpenChange={setReceiptOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {t('documents.receipt.title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('documents.receipt.description')}
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitReceipt} className="space-y-4">
                            <FormField
                                id="receipt-email"
                                label={t('documents.to')}
                                hint={
                                    delivery.email
                                        ? undefined
                                        : t('documents.no_email')
                                }
                                error={receipt.errors.email}
                            >
                                <Input
                                    id="receipt-email"
                                    type="email"
                                    inputMode="email"
                                    value={receipt.data.email}
                                    onChange={(e) =>
                                        receipt.setData('email', e.target.value)
                                    }
                                    required
                                />
                            </FormField>
                            <Button
                                type="submit"
                                className="h-11 w-full"
                                disabled={receipt.processing}
                            >
                                <Send /> {t('documents.receipt.send')}
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
            )}

            {sms && sms.mode === 'automatic' && (
                <Dialog open={smsOpen} onOpenChange={setSmsOpen}>
                    <DialogContent
                        className="top-4 translate-y-0 sm:top-[8vh]"
                        onOpenAutoFocus={(e) => e.preventDefault()}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {t('messages.sms_confirm.title', {
                                    kind: kindLabel,
                                    number,
                                })}
                            </DialogTitle>
                            <DialogDescription>
                                {t('messages.sms_confirm.description')}
                            </DialogDescription>
                        </DialogHeader>
                        <div
                            className="min-w-0 space-y-3"
                            data-test="sms-confirm"
                        >
                            <p className="text-sm">
                                <span className="text-muted-foreground">
                                    {t('messages.sms_confirm.to')}
                                </span>{' '}
                                <span className="font-medium">
                                    {formatPhone(
                                        sms.phone,
                                        auth.company?.country ?? 'US',
                                    )}
                                </span>
                            </p>
                            <p className="rounded-xl border bg-muted/40 p-3 text-sm [overflow-wrap:anywhere] whitespace-pre-wrap">
                                {sms.text}
                            </p>
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11"
                                    onClick={() => setSmsOpen(false)}
                                >
                                    {t('messages.sms_confirm.cancel')}
                                </Button>
                                <Button
                                    type="button"
                                    className="h-11"
                                    disabled={smsSending}
                                    onClick={confirmSms}
                                >
                                    <Send /> {t('messages.sms_confirm.send')}
                                </Button>
                            </div>
                        </div>
                    </DialogContent>
                </Dialog>
            )}

            {reviewPrompt && (
                <ReviewPromptDialog
                    prompt={reviewPrompt}
                    open={reviewOpen}
                    onOpenChange={setReviewOpen}
                />
            )}
        </>
    );

    return {
        canSend: delivery.can_send,
        openEmail: () => setEmailOpen(true),
        sendSms: canSms && sms?.blocked === null ? sendSms : null,
        smsBlocked: canSms ? (sms?.blocked ?? null) : null,
        copyLink,
        openReceipt: delivery.receipt_url ? () => setReceiptOpen(true) : null,
        dialogs,
    };
}

export type DocumentDeliveryActions = ReturnType<typeof useDocumentDelivery>;

/**
 * "Send ▾": Email, SMS, Copy link in a sheet from the bottom (UX-REDESIGN §10.2, §10.4).
 */
export function SendMenu({
    actions,
    label,
    primary = false,
}: {
    actions: DocumentDeliveryActions;
    label?: string;
    primary?: boolean;
}) {
    const t = useTrans();

    if (!actions.canSend) {
        return null;
    }

    return (
        <ActionSheet
            title={label ?? t('documents.send_menu')}
            description={
                actions.smsBlocked
                    ? t('messages.sms_blocked', { reason: actions.smsBlocked })
                    : undefined
            }
            trigger={(open) => (
                <Button
                    type="button"
                    variant={primary ? 'default' : 'outline'}
                    className="h-12 md:h-11"
                    onClick={open}
                    data-test="send-menu"
                >
                    <Send /> {label ?? t('documents.send_menu')}
                    <ChevronDown className="opacity-70" />
                </Button>
            )}
            items={[
                {
                    key: 'email',
                    label: t('documents.send_email'),
                    icon: <Mail />,
                    onSelect: actions.openEmail,
                },
                actions.sendSms !== null && {
                    key: 'sms',
                    label: t('messages.send_by_sms'),
                    icon: <MessageSquare />,
                    onSelect: actions.sendSms,
                },
                {
                    key: 'link',
                    label: t('documents.copy_link'),
                    icon: <Link2 />,
                    onSelect: () => void actions.copyLink(),
                },
            ]}
        />
    );
}

/** "Sent to jane@example.com Oct 6, 9:14 AM · Viewed Oct 6, 10:02 AM". */
export function DeliveryStatus({ delivery }: { delivery: Delivery }) {
    const t = useTrans();
    const time = useCompanyTime();

    if (!delivery.sent_at && !delivery.viewed_at) {
        return null;
    }

    return (
        <div className="space-y-0.5 text-xs text-muted-foreground">
            {delivery.sent_at && delivery.sent_to && (
                <p className="flex items-center gap-1">
                    <Send className="size-3 shrink-0" />
                    {t('documents.sent_on', {
                        email: delivery.sent_to,
                        date: time.dateTime(delivery.sent_at),
                    })}
                </p>
            )}
            {delivery.viewed_at && (
                <p className="flex items-center gap-1">
                    <Eye className="size-3 shrink-0" />
                    {t('documents.viewed_on', {
                        date: time.dateTime(delivery.viewed_at),
                    })}
                </p>
            )}
        </div>
    );
}
