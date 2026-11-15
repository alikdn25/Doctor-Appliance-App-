import { Head, router } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import {
    QUICK_BOOKING_RESULT_KEY,
    QUICK_BOOKING_TEXT_KEY,
} from '@/lib/quick-booking';
import { create } from '@/routes/jobs';
import { parse } from '@/routes/jobs/quick-booking';

const readText = () => {
    try {
        return sessionStorage.getItem(QUICK_BOOKING_TEXT_KEY) ?? '';
    } catch {
        return '';
    }
};

/**
 * Quick booking (Owner and Office): one big text field — typed, dictated with the phone keyboard's microphone or an
 * SMS pasted in — and Parse. The AI reads it and the usual Book customer form opens filled in; nothing is saved until
 * the person presses Save there. When the AI does not answer, the message is shown and the text stays.
 */
export default function QuickBooking() {
    const t = useTrans();
    const [text, setText] = useState(readText);
    const [parsing, setParsing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        try {
            sessionStorage.setItem(QUICK_BOOKING_TEXT_KEY, text);
        } catch {
            // Without storage the text simply is not kept.
        }
    }, [text]);

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
                try {
                    sessionStorage.setItem(
                        QUICK_BOOKING_RESULT_KEY,
                        JSON.stringify(body),
                    );
                } catch {
                    throw new Error(t('jobs.quick_booking.failed'));
                }
                router.visit(create({ query: { book: 1, quick: 1 } }).url);
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
                    className="min-h-[50vh] text-base"
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
                <StickyActionBar>
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        disabled={text.trim() === ''}
                        loading={parsing}
                        data-test="quick-booking-parse"
                    >
                        <Sparkles /> {t('jobs.quick_booking.parse')}
                    </Button>
                </StickyActionBar>
            </form>
        </>
    );
}
