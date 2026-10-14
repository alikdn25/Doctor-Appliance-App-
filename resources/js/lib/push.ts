/**
 * Push notifications on this phone or browser (Web Push). The service worker (public/sw.js) shows them.
 */

export type PushState = 'unsupported' | 'blocked' | 'off' | 'on';

const csrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

const supported = () =>
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (!supported()) {
        return null;
    }

    return (
        (await navigator.serviceWorker.getRegistration('/')) ??
        (await navigator.serviceWorker.register('/sw.js'))
    );
}

function keyBytes(base64: string): Uint8Array<ArrayBuffer> {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

async function send(url: string, method: 'POST' | 'DELETE', body: unknown) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
}

export async function pushState(): Promise<PushState> {
    if (!supported()) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    const reg = await registration();
    const subscription = await reg?.pushManager.getSubscription();

    return subscription ? 'on' : 'off';
}

export async function enablePush(
    publicKey: string,
    url: string,
): Promise<PushState> {
    const reg = await registration();

    if (!reg) {
        return 'unsupported';
    }

    if ((await Notification.requestPermission()) !== 'granted') {
        return 'blocked';
    }

    const subscription =
        (await reg.pushManager.getSubscription()) ??
        (await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyBytes(publicKey),
        }));

    await send(url, 'POST', subscription.toJSON());

    return 'on';
}

export async function disablePush(url: string): Promise<PushState> {
    const reg = await registration();
    const subscription = await reg?.pushManager.getSubscription();

    if (subscription) {
        await send(url, 'DELETE', { endpoint: subscription.endpoint }).catch(
            () => undefined,
        );
        await subscription.unsubscribe();
    }

    return 'off';
}

/** On sign-out: this device stops receiving the person's notifications. */
export async function forgetPushOnThisDevice(): Promise<void> {
    try {
        const reg = supported()
            ? await navigator.serviceWorker.getRegistration('/')
            : null;
        await (await reg?.pushManager.getSubscription())?.unsubscribe();
    } catch {
        // Nothing to forget.
    }
}
