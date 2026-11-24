import { useForm } from '@inertiajs/react';
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

/**
 * "Send Google Review request?" after the invoice is sent and after it is paid in full (UX-REDESIGN §18). The text
 * of the Review request template is shown with the customer's details filled in and can be changed for this one
 * customer; Send emails it with the location's review link (added back at the end if it was removed) and the review
 * picture — email only (owner's task of November 22). An email typed here is kept on the customer. Don't send closes.
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
    // Until the text is changed by hand it follows the chosen location (its own review link).
    const [edited, setEdited] = useState(false);
    const form = useForm({
        google_profile_id: '',
        email: '',
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
                email: prompt.email ?? '',
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
        profile !== undefined &&
        /^\S+@\S+\.\S+$/.test(form.data.email.trim()) &&
        !form.processing;

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (!canSend) {
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
                        id="review-email"
                        label={t('reviews.prompt.email')}
                        error={form.errors.email}
                    >
                        <Input
                            id="review-email"
                            type="email"
                            inputMode="email"
                            autoComplete="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                            data-test="review-email"
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
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('reviews.prompt.dont_send')}
                        </Button>
                        <Button
                            type="submit"
                            className="min-h-11"
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
