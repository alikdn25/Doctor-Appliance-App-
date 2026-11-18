import { Head, useForm } from '@inertiajs/react';
import { CalendarCheck, MapPin } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Window = {
    date: string;
    start: string;
    end: string;
    nearby: string | null;
};

type Props =
    | {
          state: 'used' | 'expired' | 'booked';
          company: string;
      }
    | {
          state: 'open';
          token: string;
          company: string;
          locale: string;
          prefill: { name: string; phone: string; address: string };
          windows: Window[];
      };

/**
 * Self-booking by link (owner's task of November 19): the customer picks a free window of the next 7 days (days with
 * a job nearby first), gives name, phone, address, what is broken and optionally a photo of the sticker.
 */
export default function SelfBooking(props: Props) {
    const t = useTrans();

    if (props.state !== 'open') {
        return (
            <main className="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-4 p-6 text-center">
                <Head title={t('booking.self.title')} />
                <CalendarCheck className="size-12 text-primary" />
                <h1 className="text-xl font-semibold">{props.company}</h1>
                <p data-test="self-booking-state">
                    {t(`booking.self.${props.state}`)}
                </p>
            </main>
        );
    }

    return <BookingForm {...props} />;
}

function BookingForm({
    token,
    company,
    locale,
    prefill,
    windows: initial,
}: Extract<Props, { state: 'open' }>) {
    const t = useTrans();
    const [windows, setWindows] = useState(initial);
    const form = useForm<{
        date: string;
        start: string;
        end: string;
        name: string;
        phone: string;
        address: string;
        problem: string;
        photo: File | null;
    }>({
        date: '',
        start: '',
        end: '',
        name: prefill.name,
        phone: prefill.phone,
        address: prefill.address,
        problem: '',
        photo: null,
    });
    const day = (date: string) =>
        new Intl.DateTimeFormat(locale, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            timeZone: 'UTC',
        }).format(new Date(`${date}T00:00:00Z`));

    // A typed address re-ranks the windows: days with a job nearby first.
    const rerank = async () => {
        if (form.data.address.trim().length < 5) return;
        try {
            const response = await fetch(`/book/${token}/nearby`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ address: form.data.address }),
            });
            if (response.ok) {
                const data = (await response.json()) as { windows: Window[] };
                setWindows(data.windows);
            }
        } catch {
            // The usual order stays.
        }
    };

    return (
        <main className="mx-auto w-full max-w-lg space-y-4 p-4 pb-16">
            <Head title={t('booking.self.title')} />
            <header className="space-y-1 pt-2">
                <h1 className="text-2xl font-semibold">
                    {t('booking.self.title')}
                </h1>
                <p className="text-muted-foreground">
                    {t('booking.self.intro', { company })}
                </p>
            </header>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/book/${token}`, { forceFormData: true });
                }}
                className="space-y-4"
            >
                <section className="space-y-2">
                    <h2 className="font-semibold">{t('booking.self.pick')}</h2>
                    {windows.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('booking.self.none')}
                        </p>
                    ) : (
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {windows.map((w) => {
                                const chosen =
                                    form.data.date === w.date &&
                                    form.data.start === w.start;

                                return (
                                    <button
                                        key={`${w.date}-${w.start}`}
                                        type="button"
                                        onClick={() =>
                                            form.setData((d) => ({
                                                ...d,
                                                date: w.date,
                                                start: w.start,
                                                end: w.end,
                                            }))
                                        }
                                        className={cn(
                                            'da-press min-h-14 rounded-xl border px-3 py-2 text-left',
                                            chosen &&
                                                'border-primary bg-primary/10',
                                        )}
                                        data-test="self-booking-window"
                                    >
                                        <span className="block font-semibold">
                                            {day(w.date)} {w.start}–{w.end}
                                        </span>
                                        {w.nearby && (
                                            <span className="flex items-center gap-1 text-xs text-emerald-700">
                                                <MapPin className="size-3" />
                                                {w.nearby}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    <InputError message={form.errors.date} />
                </section>

                <div className="space-y-1">
                    <Label htmlFor="sb-name">{t('booking.self.name')}</Label>
                    <Input
                        id="sb-name"
                        autoComplete="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={form.errors.name} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sb-phone">{t('booking.self.phone')}</Label>
                    <Input
                        id="sb-phone"
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                    <InputError message={form.errors.phone} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sb-address">
                        {t('booking.self.address')}
                    </Label>
                    <Input
                        id="sb-address"
                        autoComplete="street-address"
                        value={form.data.address}
                        onChange={(e) =>
                            form.setData('address', e.target.value)
                        }
                        onBlur={() => void rerank()}
                    />
                    <InputError message={form.errors.address} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sb-problem">
                        {t('booking.self.problem')}
                    </Label>
                    <Textarea
                        id="sb-problem"
                        rows={3}
                        placeholder={t('booking.self.problem_hint')}
                        value={form.data.problem}
                        onChange={(e) =>
                            form.setData('problem', e.target.value)
                        }
                    />
                    <InputError message={form.errors.problem} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sb-photo">{t('booking.self.photo')}</Label>
                    <Input
                        id="sb-photo"
                        type="file"
                        accept="image/*"
                        capture="environment"
                        onChange={(e) =>
                            form.setData('photo', e.target.files?.[0] ?? null)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('booking.self.photo_hint')}
                    </p>
                    <InputError message={form.errors.photo} />
                </div>
                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    disabled={form.data.date === ''}
                    loading={form.processing}
                    data-test="self-booking-send"
                >
                    {t('booking.self.send')}
                </Button>
            </form>
        </main>
    );
}
