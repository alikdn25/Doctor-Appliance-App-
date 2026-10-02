import { useForm } from '@inertiajs/react';
import { Download, Eye, Mail, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { FormField } from '@/components/form-field';
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
import { useTrans } from '@/lib/i18n';

export type Delivery = {
    pdf_url: string;
    send_url: string;
    public_url: string | null;
    can_send: boolean;
    sent_at: string | null;
    sent_to: string | null;
    viewed_at: string | null;
    email: string;
    message: string;
};

/**
 * PDF and "Send by email" for an estimate or invoice, with when it was sent and viewed.
 */
export function DocumentDelivery({
    delivery,
    kindLabel,
    number,
}: {
    delivery: Delivery;
    kindLabel: string;
    number: string;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const [open, setOpen] = useState(false);
    const form = useForm({ email: delivery.email, message: delivery.message });

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData({ email: delivery.email, message: delivery.message });
        }
        // Reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(delivery.send_url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <section className="space-y-2">
            <div className="grid grid-cols-2 gap-2">
                <Button variant="outline" className="h-11" asChild>
                    <a href={delivery.pdf_url} target="_blank" rel="noreferrer">
                        <Download /> {t('documents.download_pdf')}
                    </a>
                </Button>
                {delivery.can_send && (
                    <Button
                        variant="outline"
                        className="h-11"
                        onClick={() => setOpen(true)}
                    >
                        <Mail /> {t('documents.send')}
                    </Button>
                )}
            </div>
            {delivery.sent_at && delivery.sent_to && (
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    <Send className="size-3" />
                    {t('documents.sent_on', {
                        email: delivery.sent_to,
                        date: time.dateTime(delivery.sent_at),
                    })}
                </p>
            )}
            {delivery.viewed_at && (
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    <Eye className="size-3" />
                    {t('documents.viewed_on', {
                        date: time.dateTime(delivery.viewed_at),
                    })}
                </p>
            )}
            {delivery.public_url && (
                <p className="truncate text-xs text-muted-foreground">
                    {t('documents.online_link')}:{' '}
                    <a
                        href={delivery.public_url}
                        target="_blank"
                        rel="noreferrer"
                        className="underline"
                    >
                        {delivery.public_url}
                    </a>
                </p>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
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
        </section>
    );
}
