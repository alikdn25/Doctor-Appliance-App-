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
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { formatPhone, phoneCharacters, toE164 } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';

/**
 * "Send Google Review request?" after the invoice is sent and after it is paid in full (UX-REDESIGN §18). The text
 * of the Review request template is shown with the customer's details filled in and can be changed for this one
 * customer; Send texts it with the location's review link (added back at the end if it was removed), Don't send
 * closes.
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
    // Until the text is changed by hand it follows the chosen location (its own review link).
    const [edited, setEdited] = useState(false);
    const form = useForm({
        google_profile_id: '',
        phone: '',
        text: '',
    });

    const profileText = (id: string) =>
        prompt.profiles.find((p) => String(p.id) === id)?.text ?? '';

    useEffect(() => {
        if (open) {
            const id = prompt.default_profile_id
                ? String(prompt.default_profile_id)
                : '';
            setEdited(false);
            form.clearErrors();
            form.setData({
                google_profile_id: id,
                phone: formatPhone(prompt.phone, country),
                text: profileText(id),
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

    // What the customer gets: the edited text, with the review link at the end if it was taken out.
    const finalText = () => {
        const text = form.data.text.trim();

        if (!profile || text === '') {
            return profile?.text ?? text;
        }

        const link = profile.review_url;

        return link && !text.includes(link) ? `${text}\n${link}` : text;
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (!canSend) {
            return;
        }

        if (prompt.mode === 'technician_phone') {
            // The text opens in the phone's messages app; the request is recorded in the background.
            const body = finalText();
            form.post(prompt.url, {
                preserveScroll: true,
                preserveState: true,
            });
            window.location.href = smsUrl(
                toE164(form.data.phone, country),
                body,
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
                className="top-4 translate-y-0 sm:top-[8vh]"
                // Nothing preselected: no button gets the focus ring when the dialog opens.
                onOpenAutoFocus={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>{t('reviews.prompt.title')}</DialogTitle>
                    <DialogDescription>
                        {t('reviews.prompt.description')}
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={submit}
                    className="space-y-4"
                    data-test="review-prompt"
                >
                    {prompt.profiles.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('reviews.prompt.no_locations')}
                        </p>
                    ) : (
                        prompt.profiles.length > 1 && (
                            <FormField
                                id="review-location"
                                label={t('reviews.prompt.location')}
                                error={form.errors.google_profile_id}
                            >
                                <NativeSelect
                                    id="review-location"
                                    value={form.data.google_profile_id}
                                    onChange={(e) =>
                                        form.setData((data) => ({
                                            ...data,
                                            google_profile_id: e.target.value,
                                            text: edited
                                                ? data.text
                                                : profileText(e.target.value),
                                        }))
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
                        )
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
                    {profile && (
                        <FormField
                            id="review-text"
                            label={t('reviews.prompt.text')}
                            hint={t('reviews.prompt.text_hint')}
                            error={form.errors.text}
                        >
                            <Textarea
                                id="review-text"
                                rows={5}
                                maxLength={1000}
                                value={form.data.text}
                                onChange={(e) => {
                                    setEdited(true);
                                    form.setData('text', e.target.value);
                                }}
                            />
                        </FormField>
                    )}
                    {prompt.blocked && (
                        <p className="text-sm text-muted-foreground">
                            {prompt.blocked}
                        </p>
                    )}
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('reviews.prompt.dont_send')}
                        </Button>
                        <Button
                            type="submit"
                            className="h-11"
                            disabled={!canSend}
                        >
                            <Send /> {t('reviews.prompt.send')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
