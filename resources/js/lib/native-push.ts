import { Capacitor } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import { router } from '@inertiajs/react';
import { sendJson } from '@/lib/http';

/**
 * Push in the Android app (owner's task of November 22): Firebase Cloud Messaging through @capacitor/push-notifications.
 * Permission is asked on the first start; while signed in, the app's token is sent to the server on every start, so
 * every push the browser / PWA gets (Invoice paid, incoming SMS, reminders, the morning summary …) reaches the app too.
 * The notification itself, its buttons and the tap are handled natively (AppMessagingService, PushActionReceiver);
 * a tap opens the page in the app. Signing out drops the token on the phone. Nothing happens in a browser.
 */
const STORAGE_KEY = 'native-push-token';

export function hasNativePush(): boolean {
    try {
        return (
            Capacitor.isNativePlatform() &&
            Capacitor.isPluginAvailable('PushNotifications')
        );
    } catch {
        return false;
    }
}

function stored(): string | null {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function store(token: string | null): void {
    try {
        if (token) localStorage.setItem(STORAGE_KEY, token);
        else localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Private storage off: the token is sent again on the next start.
    }
}

let listening = false;
let signedIn = false;

async function register(): Promise<void> {
    if (!listening) {
        listening = true;
        await PushNotifications.addListener('registration', ({ value }) => {
            store(value);
            if (signedIn) {
                void sendJson('/settings/notifications/fcm', 'POST', {
                    token: value,
                });
            }
        });
        // A notification shown by the plugin itself (not ours) still opens its page.
        await PushNotifications.addListener(
            'pushNotificationActionPerformed',
            ({ notification }) => {
                const url = (notification.data as { url?: string } | undefined)
                    ?.url;
                if (url) router.visit(url);
            },
        );
    }

    let permission = await PushNotifications.checkPermissions();
    if (
        permission.receive === 'prompt' ||
        permission.receive === 'prompt-with-rationale'
    ) {
        permission = await PushNotifications.requestPermissions();
    }
    if (permission.receive === 'granted') {
        await PushNotifications.register();
    }
}

async function signOut(): Promise<void> {
    if (!stored()) return;
    store(null);
    // The install's token goes away: pushes for the person who signed out stop on this phone.
    await PushNotifications.unregister().catch(() => {});
}

/** Called once at start: asks for the permission on the first start, then follows sign-in and sign-out. */
export function initNativePush(): void {
    if (!hasNativePush()) return;

    void register().catch(() => {});

    router.on('navigate', (event) => {
        const user = (event.detail.page.props as { auth?: { user?: unknown } })
            .auth?.user;

        if (user && !signedIn) {
            signedIn = true;
            const token = stored();
            if (token) {
                void sendJson('/settings/notifications/fcm', 'POST', { token });
            } else {
                void register().catch(() => {});
            }
        } else if (!user) {
            signedIn = false;
            void signOut();
        }
    });
}
