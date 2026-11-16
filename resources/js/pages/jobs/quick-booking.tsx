import { Head, router } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { ChatDock } from '@/components/ui/chat-dock';
import { MicButton } from '@/components/ui/mic-button';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import {
    clearBooking,
    handQuickResult,
    keepQuickBookingText,
    quickBookingText,
} from '@/lib/quick-booking';
import { create } from '@/routes/jobs';
import { parse } from '@/routes/jobs/quick-booking';

/**
 * Quick booking (Owner and Office): one big text field — typed, dictated (the microphone next to Parse, EN / RU, or
 * the keyboard's) or an SMS pasted in — and Parse, docked right on top of the keyboard while typing. The AI reads it and the usual Book customer form opens filled in; nothing is saved until
 * the person presses Save there. When the AI does not answer, the message is shown and the text stays.
 *
 * The text lives in memory only: closing this screen (✕, back, a swipe) empties it; it is kept only on the way to the
 * filled form, for "Back to the text".
 */
export default function QuickBooking() {
    const t = useTrans();
    const [text, setText] = useState(quickBookingText);
    const [parsing, setParsing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const toForm = useRef(false);

    useEffect(() => keepQuickBookingText(text), [text]);
    useEffect(
        () => () => {
            if (!toForm.current) clearBooking();
        },
        [],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (text.trim() === '' || parsing) return;
        setParsing(true);
        setError(null);
        fetch(parse().url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ text }),
        })
            .then(async (response) => {
                const body = await response.json().catch(() => null);
                if (!response.ok) {
                    throw new Error(
                        body?.message ?? t('jobs.quick_booking.failed'),
                    );
                }
                handQuickResult(body);
                toForm.current = true;
                router.visit(create({ query: { book: 1, quick: 1 } }).url, {
                    onCancel: () => {
                        toForm.current = false;
                    },
                });
            })
            .catch((e: Error) => {
                setError(e.message || t('jobs.quick_booking.failed'));
                setParsing(false);
            });
    };

    return (
        <>
            <Head title={t('jobs.quick_booking.title')} />
            <ScreenHeader title={t('jobs.quick_booking.title')} />
            <form
                onSubmit={submit}
                className="mx-auto flex w-full max-w-2xl flex-col gap-3 p-4 sm:p-6"
                data-test="quick-booking"
            >
                <label
                    htmlFor="quick-booking-text"
                    className="text-sm text-muted-foreground"
                >
                    {t('jobs.quick_booking.hint')}
                </label>
                <Textarea
                    id="quick-booking-text"
                    autoFocus
                    rows={12}
                    maxLength={5000}
                    className="min-h-[calc(var(--app-vvh,100dvh)*0.4)] text-base"
                    placeholder={t('jobs.quick_booking.placeholder')}
                    value={text}
                    onChange={(event) => {
                        setText(event.target.value);
                        setError(null);
                    }}
                    data-test="quick-booking-text"
                />
                {error && (
                    <p
                        role="alert"
                        className="rounded-2xl border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                        data-test="quick-booking-error"
                    >
                        {error}
                    </p>
                )}
                <ChatDock scrollOnKeyboard={false}>
                    <div className="flex items-end gap-2">
                        <MicButton
                            value={text}
                            onChange={(value) => {
                                setText(value);
                                setError(null);
                            }}
                            disabled={parsing}
                        />
                        <Button
                            type="submit"
                            size="lg"
                            className="min-w-0 flex-1"
                            disabled={text.trim() === ''}
                            loading={parsing}
                            data-test="quick-booking-parse"
                        >
                            <Sparkles /> {t('jobs.quick_booking.parse')}
                        </Button>
                    </div>
                </ChatDock>
            </form>
        </>
    );
}
