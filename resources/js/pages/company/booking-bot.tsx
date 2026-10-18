import { Head, router, useForm } from '@inertiajs/react';
import { Bot, Copy } from 'lucide-react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { bookingBot } from '@/routes/company';
import { destroy, store } from '@/routes/company/booking-bot';

type Token = {
    id: number;
    name: string;
    hint: string;
    requests_per_minute: number;
    bookings_per_hour: number;
    last_used_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
};

/**
 * Company → Booking bot (Owner): API tokens of the website booking assistant.
 */
export default function BookingBot({
    tokens,
    apiUrl,
    newToken,
}: {
    tokens: Token[];
    apiUrl: string;
    newToken: string | null;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const form = useForm({
        name: '',
        bookings_per_hour: 5,
        requests_per_minute: 30,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url, { onSuccess: () => form.reset('name') });
    };

    const copy = async (text: string) => {
        await navigator.clipboard?.writeText(text);
        toast.success(t('bot.settings.copied'));
    };

    return (
        <>
            <Head title={t('bot.settings.title')} />

            <div className="max-w-3xl space-y-4 p-4">
                <PageHeader
                    title={t('bot.settings.title')}
                    description={t('bot.settings.hint')}
                />

                <p className="text-sm">
                    <span className="text-muted-foreground">
                        {t('bot.settings.api_url')}:
                    </span>{' '}
                    <code className="break-all">{apiUrl}</code>
                </p>

                {newToken && (
                    <div
                        className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm shadow-sm dark:bg-amber-950/30"
                        data-test="new-bot-token"
                    >
                        <p className="font-medium">
                            {t('bot.settings.new_token')}
                        </p>
                        <code className="block break-all">{newToken}</code>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => copy(newToken)}
                        >
                            <Copy /> {t('bot.settings.copy')}
                        </Button>
                    </div>
                )}

                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-xl border bg-card p-3 shadow-sm sm:grid-cols-[1fr_auto_auto_auto] sm:items-end"
                >
                    <div className="grid gap-1">
                        <Label htmlFor="bot-name">
                            {t('bot.settings.name')}
                        </Label>
                        <Input
                            id="bot-name"
                            value={form.data.name}
                            placeholder={t('bot.settings.name_placeholder')}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="bot-bookings">
                            {t('bot.settings.bookings_per_hour')}
                        </Label>
                        <Input
                            id="bot-bookings"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={50}
                            className="sm:w-28"
                            value={form.data.bookings_per_hour}
                            onChange={(e) =>
                                form.setData(
                                    'bookings_per_hour',
                                    Number(e.target.value),
                                )
                            }
                        />
                        <InputError message={form.errors.bookings_per_hour} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="bot-requests">
                            {t('bot.settings.requests_per_minute')}
                        </Label>
                        <Input
                            id="bot-requests"
                            type="number"
                            inputMode="numeric"
                            min={5}
                            max={120}
                            className="sm:w-28"
                            value={form.data.requests_per_minute}
                            onChange={(e) =>
                                form.setData(
                                    'requests_per_minute',
                                    Number(e.target.value),
                                )
                            }
                        />
                        <InputError message={form.errors.requests_per_minute} />
                    </div>
                    <Button
                        type="submit"
                        className="h-11"
                        disabled={form.processing}
                    >
                        <Bot /> {t('bot.settings.create')}
                    </Button>
                </form>

                {tokens.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('bot.settings.empty')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card shadow-sm">
                        {tokens.map((token) => (
                            <li
                                key={token.id}
                                className="flex items-center gap-3 p-3 text-sm"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        {token.name}{' '}
                                        <span className="text-muted-foreground">
                                            ••••{token.hint}
                                        </span>
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            t('bot.settings.limits', {
                                                bookings:
                                                    token.bookings_per_hour,
                                                requests:
                                                    token.requests_per_minute,
                                            }),
                                            token.revoked_at
                                                ? t('bot.settings.revoked_on', {
                                                      date: time.date(
                                                          token.revoked_at,
                                                      ),
                                                  })
                                                : token.last_used_at
                                                  ? t(
                                                        'bot.settings.last_used',
                                                        {
                                                            date: time.dateTime(
                                                                token.last_used_at,
                                                            ),
                                                        },
                                                    )
                                                  : t(
                                                        'bot.settings.never_used',
                                                    ),
                                        ].join(' · ')}
                                    </p>
                                </div>
                                {!token.revoked_at && (
                                    <Button
                                        variant="outline"
                                        className="h-11 shrink-0"
                                        onClick={() =>
                                            window.confirm(
                                                t(
                                                    'bot.settings.revoke_confirm',
                                                ),
                                            ) &&
                                            router.delete(destroy(token.id).url)
                                        }
                                    >
                                        {t('bot.settings.revoke')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

BookingBot.layout = {
    breadcrumbs: [{ title: 'bot.settings.title', href: bookingBot() }],
};
