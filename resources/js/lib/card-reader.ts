import { Capacitor, registerPlugin } from '@capacitor/core';
import type { PluginListenerHandle } from '@capacitor/core';

/**
 * The card reader in the Android app (owner's task of November 21): a Capacitor plugin written in Kotlin
 * (android/app/src/main/java/.../SquareReaderPlugin.kt) around the Square Mobile Payments SDK. In a browser the plugin
 * is not there: Charge reader is hidden and only Cash and E-transfer are offered.
 */
export type CardReader = {
    id: string;
    name: string | null;
    model: string;
    serial: string | null;
    /** ready | connecting | unavailable | faulty */
    status: string;
    battery: number | null;
    charging: boolean;
};

export type ReaderSession = {
    application_id: string;
    access_token: string;
    location_id: string;
    environment: string;
};

type SquareReaderPlugin = {
    authorize(options: {
        accessToken: string;
        locationId: string;
    }): Promise<{ authorized: boolean; location: string | null }>;
    isAuthorized(): Promise<{ authorized: boolean; locationId: string | null }>;
    deauthorize(): Promise<void>;
    requestReaderPermissions(): Promise<{ granted: boolean }>;
    readers(): Promise<{ readers: CardReader[] }>;
    pair(): Promise<{ paired: boolean }>;
    stopPairing(): Promise<void>;
    forget(options: { id: string }): Promise<void>;
    retryConnection(options: { id: string }): Promise<void>;
    showSettings(): Promise<void>;
    charge(options: {
        amount: number;
        currency: string;
        referenceId: string;
        note: string;
        paymentAttemptId: string;
    }): Promise<{ paymentId: string; status: string }>;
    addListener(
        event: 'readerChanged',
        callback: (data: { readers: CardReader[] }) => void,
    ): Promise<PluginListenerHandle>;
};

export const SquareReader = registerPlugin<SquareReaderPlugin>('SquareReader');

/** True only inside the Android app that carries the card reader plugin. */
export function hasCardReader(): boolean {
    try {
        return (
            Capacitor.isNativePlatform() &&
            Capacitor.isPluginAvailable('SquareReader')
        );
    } catch {
        return false;
    }
}

/** An error from the plugin: code (CANCELED, TIMEOUT, NOT_AUTHORIZED …) and a message. */
export function readerError(error: unknown): { code: string; message: string } {
    const e = error as { code?: string; message?: string } | null;

    return {
        code: typeof e?.code === 'string' ? e.code : 'UNKNOWN',
        message: typeof e?.message === 'string' ? e.message : String(error),
    };
}

/**
 * Authorizes the SDK with the company's Square token and location from the server, unless it already is for this
 * location. The token is never stored by the web page.
 */
export async function ensureReaderAuthorized(sessionUrl: string): Promise<void> {
    const response = await fetch(sessionUrl, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw Object.assign(new Error(body?.message ?? 'Square'), {
            code: 'NOT_CONNECTED',
        });
    }

    const session = body as ReaderSession;
    const state = await SquareReader.isAuthorized();

    if (state.authorized && state.locationId === session.location_id) {
        return;
    }

    if (state.authorized) {
        await SquareReader.deauthorize();
    }

    await SquareReader.authorize({
        accessToken: session.access_token,
        locationId: session.location_id,
    });
}
