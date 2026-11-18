import { Head, Link, router, useForm } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCompanyTime } from '@/lib/datetime';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { disablePush, enablePush, pushState } from '@/lib/push';
import type { PushState } from '@/lib/push';
import { cn } from '@/lib/utils';
import { today as todayRoute } from '@/routes';
import { index, morning as morningRoute, read } from '@/routes/notifications';
import { subscribe } from '@/routes/notifications/push';

type Item = {
    id: string;
    kind: string | null;
    title: string;
    body: string;
    url: string | null;
    actions?: { action: string; title: string; url: string | null }[];
    action_url?: string | null;
    read: boolean;
    at: string | null;
};

/**
 * The buttons of a notification with actions, also here in the list: a page opens; anything else runs on the server
 * (the tap is the confirmation) and its answer is shown in place of the buttons.
 */
function ActionButtons({ item }: { item: Item }) {
    const [result, setResult] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    if (!item.actions || item.actions.length === 0) return null;
    if (result) {
        return (
            <p className="text-sm font-medium text-emerald-700" role="status">
                {result}
            </p>
        );
    }

    const run = async (key: string) => {
        if (!item.action_url) return;
        setBusy(true);
        try {
            const response = await fetch(
                item.action_url.replace('__action__', key),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                },
            );
            const data = (await response.json()) as { message?: string };
            setResult(data.message ?? '');
        } catch {
            setResult('…');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div
            className="flex flex-wrap gap-2 pt-1"
            data-test="notification-actions"
        >
            {item.actions.map((a) =>
                a.url ? (
                    <Button key={a.action} asChild size="sm" variant="outline">
                        <Link href={a.url}>{a.title}</Link>
                    </Button>
                ) : (
                    <Button
                        key={a.action}
                        size="sm"
                        variant={a.action === 'main' ? 'default' : 'outline'}
                        disabled={busy}
                        onClick={() => void run(a.action)}
                    >
                        {a.title}
                    </Button>
                ),
            )}
        </div>
    );
}

/**
 * Settings → Notifications: push on this phone, and every notification kept in the app.
 */
export default function Notifications({
    notifications,
    push,
    morning = { enabled: true, time: '07:00' },
}: {
    notifications: Item[];
    push: { available: boolean; publicKey: string | null; devices: number };
    morning?: { enabled: boolean; time: string };
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const [state, setState] = useState<PushState | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        void pushState().then(setState);
    }, []);

    const turnOn = async () => {
        setFailed(false);

        try {
            setState(await enablePush(push.publicKey ?? '', subscribe().url));
        } catch {
            setFailed(true);
        }
    };

    const turnOff = async () => setState(await disablePush(subscribe().url));

    const unread = notifications.some((n) => !n.read);

    return (
        <>
            <Head title={t('notifications.page.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('notifications.page.title')}
                    description={t('notifications.page.description')}
                />

                <MorningSettings morning={morning} />

                <section
                    className="space-y-2 rounded-xl border bg-card p-3 shadow-sm"
                    data-test="push-settings"
                >
                    <p className="font-medium">
                        {t('notifications.page.push_title')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {!push.available
                            ? t('notifications.page.push_unavailable')
                            : state === 'unsupported'
                              ? t('notifications.page.push_unsupported')
                              : state === 'blocked'
                                ? t('notifications.page.push_blocked')
                                : state === 'on'
                                  ? t('notifications.page.push_on')
                                  : t('notifications.page.push_hint')}
                    </p>
                    {failed && (
                        <p className="text-sm text-destructive">
                            {t('notifications.page.push_failed')}
                        </p>
                    )}
                    {push.available && state === 'off' && (
                        <Button className="h-11" onClick={turnOn}>
                            <Bell /> {t('notifications.page.push_enable')}
                        </Button>
                    )}
                    {push.available && state === 'on' && (
                        <Button
                            variant="outline"
                            className="h-11"
                            onClick={turnOff}
                        >
                            <BellOff /> {t('notifications.page.push_disable')}
                        </Button>
                    )}
                </section>

                {unread && (
                    <Button
                        variant="outline"
                        className="h-11"
                        onClick={() =>
                            router.post(
                                read().url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('notifications.page.mark_read')}
                    </Button>
                )}

                {notifications.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('notifications.page.empty')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card shadow-sm">
                        {notifications.map((n) => (
                            <li
                                key={n.id}
                                className={cn(
                                    'space-y-1 p-3 text-sm',
                                    !n.read && 'bg-primary/5',
                                )}
                                data-test="notification"
                            >
                                <p className="font-semibold">{n.title}</p>
                                <p className="whitespace-pre-line text-muted-foreground">
                                    {n.body}
                                </p>
                                <ActionButtons item={n} />
                                <p className="flex items-center gap-3 text-xs text-muted-foreground">
                                    {n.at && time.dateTime(n.at)}
                                    {n.url && (
                                        <Link
                                            href={n.url}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {t('notifications.page.open')}
                                        </Link>
                                    )}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'notifications.page.title', href: index() }],
};

/** The morning summary (owner's task of November 18): on/off and the time, for this company. */
function MorningSettings({
    morning,
}: {
    morning: { enabled: boolean; time: string };
}) {
    const t = useTrans();
    const form = useForm(morning);

    return (
        <form
            className="space-y-3 rounded-xl border bg-card p-3 shadow-sm"
            data-test="morning-settings"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(morningRoute().url, { preserveScroll: true });
            }}
        >
            <div>
                <p className="font-medium">
                    {t('automation.today.settings_title')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('automation.today.settings_hint')}
                </p>
            </div>
            <label className="flex min-h-11 items-center gap-3 text-sm">
                <Checkbox
                    checked={form.data.enabled}
                    onCheckedChange={(c) => form.setData('enabled', c === true)}
                />
                {t('automation.today.enabled')}
            </label>
            <div className="flex items-end gap-3">
                <div className="space-y-1">
                    <Label htmlFor="morning-time">
                        {t('automation.today.time')}
                    </Label>
                    <Input
                        id="morning-time"
                        type="time"
                        step={300}
                        value={form.data.time}
                        onChange={(e) => form.setData('time', e.target.value)}
                    />
                </div>
                <Button type="submit" loading={form.processing}>
                    {t('common.save')}
                </Button>
                <Button variant="ghost" asChild>
                    <Link href={todayRoute()}>
                        {t('automation.today.title')}
                    </Link>
                </Button>
            </div>
        </form>
    );
}
