import { Head, router } from '@inertiajs/react';
import { Camera, Paperclip, Sparkles, X } from 'lucide-react';
import type { ChangeEvent, FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { ChatDock } from '@/components/ui/chat-dock';
import { IconButton } from '@/components/ui/icon-button';
import { MicButton, micFromShortcut } from '@/components/ui/mic-button';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { compressImage } from '@/lib/image';
import { useTrans } from '@/lib/i18n';
import {
    clearBooking,
    handQuickResult,
    keepQuickBookingPhotos,
    keepQuickBookingText,
    MAX_BOOKING_PHOTOS,
    quickBookingPhotos,
    quickBookingText,
} from '@/lib/quick-booking';
import type { QuickBookingPhoto } from '@/lib/quick-booking';
import { create } from '@/routes/jobs';
import { parse } from '@/routes/jobs/quick-booking';

/**
 * Quick booking (Owner and Office): one big text field — typed, dictated (the microphone next to Parse, EN / RU, or
 * the keyboard's) or an SMS pasted in — and Parse, docked right on top of the keyboard while typing. The AI reads it and the usual Book customer form opens filled in; nothing is saved until
 * the person presses Save there. When the AI does not answer, the message is shown and the text stays.
 *
 * The paperclip next to the microphone adds up to 5 photos (gallery or camera, shrunk on the phone to 1600 px): a
 * screenshot of the customer's message, a rating plate. Photos only, text only, or both. Their previews sit above the
 * text field, each with ✕; after saving the booking they become the job's attachments (Files → Attachments).
 * "Booking from photo" in "+" opens the picker in the menu itself; this screen then opens with those photos and starts
 * reading them at once. Opened by a link (`?photo=1`), it shows a big "Choose photo" on top instead (a picker never
 * opens by itself: a phone only allows it on a tap).
 *
 * The text and photos live in memory only: closing this screen (✕, back, a swipe) empties them; they are kept only
 * on the way to the filled form, for "Back to the text".
 */
export default function QuickBooking() {
    const t = useTrans();
    const [text, setText] = useState(quickBookingText);
    const [photos, setPhotos] =
        useState<QuickBookingPhoto[]>(quickBookingPhotos);
    const [parsing, setParsing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const toForm = useRef(false);
    const picker = useRef<HTMLInputElement>(null);
    const [photoFirst] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('photo') === '1',
    );

    useEffect(() => keepQuickBookingText(text), [text]);
    useEffect(() => keepQuickBookingPhotos(photos), [photos]);

    const addPhotos = async (event: ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(event.target.files ?? []);
        event.target.value = '';
        const room = MAX_BOOKING_PHOTOS - photos.length;
        setError(
            files.length > room
                ? t('jobs.quick_booking.photo_limit', {
                      count: MAX_BOOKING_PHOTOS,
                  })
                : null,
        );
        const added = await Promise.all(
            files.slice(0, Math.max(0, room)).map(async (file) => {
                const { blob, filename } = await compressImage(file, 1600);

                return {
                    id: `${Date.now()}-${Math.random()}`,
                    blob,
                    filename,
                    preview: URL.createObjectURL(blob),
                };
            }),
        );
        setPhotos((list) => [...list, ...added].slice(0, MAX_BOOKING_PHOTOS));
    };
    const removePhoto = (id: string) =>
        setPhotos((list) => {
            const photo = list.find((p) => p.id === id);
            if (photo) URL.revokeObjectURL(photo.preview);

            return list.filter((p) => p.id !== id);
        });
    const empty = text.trim() === '' && photos.length === 0;
    useEffect(
        () => () => {
            if (!toForm.current) clearBooking();
        },
        [],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        read();
    };

    const read = () => {
        if (empty || parsing) return;
        setParsing(true);
        setError(null);
        const body = new FormData();
        if (text.trim() !== '') body.append('text', text);
        photos.forEach((photo) =>
            body.append('photos[]', photo.blob, photo.filename),
        );
        fetch(parse().url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body,
        })
            .then(async (response) => {
                const answer = await response.json().catch(() => null);
                if (!response.ok) {
                    throw new Error(
                        response.status === 413 ||
                            Object.keys(answer?.errors ?? {}).some((key) =>
                                key.startsWith('photos'),
                            )
                            ? t('jobs.quick_booking.photo_failed')
                            : (answer?.message ??
                                  t('jobs.quick_booking.failed')),
                    );
                }
                handQuickResult(answer);
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
                {photoFirst && photos.length === 0 && (
                    <Button
                        type="button"
                        size="lg"
                        className="min-h-16 w-full text-lg"
                        onClick={() => picker.current?.click()}
                        data-test="quick-booking-choose-photo"
                    >
                        <Camera /> {t('jobs.quick_booking.choose_photo')}
                    </Button>
                )}
                <label
                    htmlFor="quick-booking-text"
                    className="text-sm text-muted-foreground"
                >
                    {t('jobs.quick_booking.hint')}
                </label>
                <Textarea
                    voice={false}
                    id="quick-booking-text"
                    autoFocus={!photoFirst}
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
                    {photos.length > 0 && (
                        <div
                            className="mb-2 flex gap-2 overflow-x-auto pt-1.5"
                            data-test="quick-booking-photos"
                        >
                            {photos.map((photo, index) => (
                                <div
                                    key={photo.id}
                                    className="relative size-16 shrink-0"
                                >
                                    <img
                                        src={photo.preview}
                                        alt={t('jobs.quick_booking.photo', {
                                            number: index + 1,
                                        })}
                                        className="size-16 rounded-xl border object-cover shadow-sm"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => removePhoto(photo.id)}
                                        className="absolute -top-1.5 -right-1.5 inline-flex size-7 items-center justify-center rounded-full bg-slate-900 text-white shadow"
                                        aria-label={t(
                                            'jobs.quick_booking.remove_photo',
                                        )}
                                        data-test="quick-booking-photo-remove"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="flex items-end gap-2">
                        <input
                            ref={picker}
                            type="file"
                            accept="image/*"
                            multiple
                            className="hidden"
                            onChange={(event) => void addPhotos(event)}
                            data-test="quick-booking-photo-input"
                        />
                        <IconButton
                            label={t('jobs.quick_booking.attach')}
                            variant="plain"
                            disabled={
                                photos.length >= MAX_BOOKING_PHOTOS || parsing
                            }
                            onClick={() => picker.current?.click()}
                            data-test="quick-booking-attach"
                        >
                            <Paperclip />
                        </IconButton>
                        <MicButton
                            autoStart={micFromShortcut()}
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
                            disabled={empty}
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
