import { Head, Link, router } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { disablePush, enablePush, pushState } from '@/lib/push';
import type { PushState } from '@/lib/push';
import { cn } from '@/lib/utils';
import { index, read } from '@/routes/notifications';
import { subscribe } from '@/routes/notifications/push';

type Item = {
    id: string;
    kind: string | null;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    at: string | null;
};

/**
 * Settings → Notifications: push on this phone, and every notification kept in the app.
 */
export default function Notifications({
    notifications,
    push,
}: {
    notifications: Item[];
    push: { available: boolean; publicKey: string | null; devices: number };
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
