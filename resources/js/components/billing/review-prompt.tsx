import { useForm, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { FormField } from '@/components/form-field';
import type { ReviewPrompt } from '@/components/messaging/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { formatPhone, phoneCharacters, toE164 } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';

/**
 * "Send Google Review request?" shown after every invoice send. Yes / No with nothing preselected; Yes reveals the
 * location, the phone (the customer's, editable) and Send, which texts the location's review link separately.
 */
export function ReviewPromptDialog({
    prompt,
    open,
    onOpenChange,
}: {
    prompt: NonNullable<ReviewPrompt>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const country = auth.company?.country ?? 'US';
    const [asked, setAsked] = useState(false);
    const form = useForm({
        google_profile_id: '',
        phone: '',
    });

    useEffect(() => {
        if (open) {
            setAsked(false);
            form.clearErrors();
            form.setData({
                google_profile_id: prompt.default_profile_id
                    ? String(prompt.default_profile_id)
                    : '',
                phone: formatPhone(prompt.phone, country),
            });
        }
        // Reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const profile = prompt.profiles.find(
        (p) => String(p.id) === form.data.google_profile_id,
    );
    const canSend =
        prompt.blocked === null &&
        profile !== undefined &&
        form.data.phone.trim() !== '' &&
        !form.processing;

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (!canSend) {
            return;
        }

        if (prompt.mode === 'technician_phone') {
            // The text opens in the phone's messages app; the request is recorded in the background.
            form.post(prompt.url, {
                preserveScroll: true,
                preserveState: true,
            });
            window.location.href = smsUrl(
                toE164(form.data.phone, country),
                profile.text,
            );
            onOpenChange(false);

            return;
        }

        form.post(prompt.url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                // Nothing preselected: no button gets the focus ring when the dialog opens.
                onOpenAutoFocus={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>{t('reviews.prompt.title')}</DialogTitle>
                    <DialogDescription>
                        {t('reviews.prompt.description')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-2 gap-2">
                    <Button
                        type="button"
                        variant={asked ? 'default' : 'outline'}
                        className="h-11"
                        aria-pressed={asked}
                        onClick={() => setAsked(true)}
                    >
                        {t('reviews.prompt.yes')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('reviews.prompt.no')}
                    </Button>
                </div>

                {asked && (
                    <form onSubmit={submit} className="space-y-4">
                        {prompt.profiles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('reviews.prompt.no_locations')}
                            </p>
                        ) : (
                            <FormField
                                id="review-location"
                                label={t('reviews.prompt.location')}
                                error={form.errors.google_profile_id}
                            >
                                <NativeSelect
                                    id="review-location"
                                    value={form.data.google_profile_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'google_profile_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="" disabled>
                                        {t('reviews.prompt.choose_location')}
                                    </option>
                                    {prompt.profiles.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                        )}
                        <FormField
                            id="review-phone"
                            label={t('reviews.prompt.phone')}
                            error={form.errors.phone}
                        >
                            <Input
                                id="review-phone"
                                type="tel"
                                inputMode="tel"
                                autoComplete="tel"
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData(
                                        'phone',
                                        phoneCharacters(e.target.value),
                                    )
                                }
                            />
                        </FormField>
                        {prompt.blocked && (
                            <p className="text-sm text-muted-foreground">
                                {prompt.blocked}
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="h-11 w-full"
                            disabled={!canSend}
                        >
                            <Send /> {t('reviews.prompt.send')}
                        </Button>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
