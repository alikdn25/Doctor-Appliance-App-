import { router, useForm } from '@inertiajs/react';
import { MessageSquare, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';
import { sms } from '@/routes/customers';
import { opened } from '@/routes/customers/messages';

export type CustomerTexting = {
    mode: string;
    can_text: boolean;
    phones: { id: number; number: string; opted_out: boolean }[];
    blocked: string | null;
    text: string;
};

/**
 * "Send SMS" on the customer profile, outside any job — for customers who do not pick up calls from unknown numbers.
 * Automatic mode sends from the company number; technician's-phone mode opens the phone's messages app.
 */
export function CustomerSms({
    customerId,
    texting,
}: {
    customerId: number;
    texting: CustomerTexting;
}) {
    const t = useTrans();
    const phoneText = usePhone();
    const [open, setOpen] = useState(false);
    const phones = texting.phones.filter((p) => !p.opted_out);
    const form = useForm({
        phone_id: phones[0]?.id ?? 0,
        body: texting.text,
    });

    if (!texting.can_text || phones.length === 0) return null;

    const onPhone = texting.mode === 'technician_phone';
    const blocked = !onPhone ? texting.blocked : null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (onPhone) {
            const phone = phones.find(
                (p) => p.id === Number(form.data.phone_id),
            );
            if (!phone) return;
            router.post(
                opened(customerId).url,
                { phone_id: phone.id, body: form.data.body },
                { preserveScroll: true, preserveState: true },
            );
            setOpen(false);
            window.location.href = smsUrl(phone.number, form.data.body);

            return;
        }
        form.post(sms(customerId).url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button
                variant="outline"
                className="h-11"
                disabled={blocked !== null}
                onClick={() => setOpen(true)}
            >
                <MessageSquare /> {t('messages.send_sms')}
            </Button>
            {blocked && (
                <p className="text-sm text-muted-foreground">
                    {t('messages.sms_blocked', { reason: blocked })}
                </p>
            )}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('messages.send_sms')}</DialogTitle>
                        <DialogDescription>
                            {onPhone
                                ? t('messages.customer_sms_phone_hint')
                                : t('messages.customer_sms_hint')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        {phones.length > 1 && (
                            <NativeSelect
                                aria-label={t('messages.customer_sms_to')}
                                value={String(form.data.phone_id)}
                                onChange={(e) =>
                                    form.setData(
                                        'phone_id',
                                        Number(e.target.value),
                                    )
                                }
                            >
                                {phones.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {phoneText(p.number)}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        {phones.length === 1 && (
                            <p className="text-sm">
                                {phoneText(phones[0].number)}
                            </p>
                        )}
                        <Textarea
                            rows={5}
                            maxLength={1000}
                            aria-label={t('documents.message')}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                        {(form.errors.body || form.errors.phone_id) && (
                            <p className="text-sm text-destructive">
                                {form.errors.body ?? form.errors.phone_id}
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="h-11 w-full"
                            disabled={
                                form.processing || form.data.body.trim() === ''
                            }
                        >
                            <Send /> {t('messages.send_sms')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
