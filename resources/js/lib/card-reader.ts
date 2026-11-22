import { Capacitor, registerPlugin } from '@capacitor/core';
import type { PluginListenerHandle } from '@capacitor/core';

/**
 * The card reader in the Android app (owner's task of November 21): a Capacitor plugin written in Kotlin
 * (android/app/src/main/java/.../SquareReaderPlugin.kt) around the Square Mobile Payments SDK. In a browser the plugin
 * is not there: Card is hidden and only Cash and E-transfer are offered.
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

/** Sent by the plugin with every error, plus the SDK's own debug fields on a Square failure. */
export type ReaderDiagnostics = {
    sdkVersion?: string;
    device?: string;
    android?: string;
    appVersion?: string;
    appBuild?: string;
    applicationId?: string;
    signingSha256?: string | null;
    nfc?: string;
    environment?: string;
    authorized?: boolean;
    sdkLocationId?: string | null;
    sdkLocationName?: string | null;
    debugCode?: string | null;
    debugMessage?: string | null;
    details?: string[];
    raw?: string;
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
    /** The phone, the app build and the SDK (sent with every failed payment). */
    diagnostics(): Promise<ReaderDiagnostics>;
    /** Cancels the payment the reader is waiting for (Cancel on Take payment). */
    cancelPayment(): Promise<{ cancelled: boolean }>;
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

export type ReaderErrorInfo = {
    code: string;
    message: string;
    data: ReaderDiagnostics;
};

/**
 * An error from the plugin: the SDK's error code (CANCELED, TIMEOUT, NOT_AUTHORIZED …), its message and, from the
 * native side, debugCode, debugMessage, details and what the phone and the app build are.
 */
export function readerError(error: unknown): ReaderErrorInfo {
    const e = error as {
        code?: string;
        message?: string;
        data?: ReaderDiagnostics;
    } | null;

    return {
        code: typeof e?.code === 'string' ? e.code : 'UNKNOWN',
        message: typeof e?.message === 'string' ? e.message : String(error),
        data: e?.data && typeof e.data === 'object' ? e.data : {},
    };
}

/** The full SDK error as text, shown on the screen exactly as Square returned it. */
export function readerErrorText(info: ReaderErrorInfo): string {
    const lines = [`Error code: ${info.code}`, `Message: ${info.message}`];
    if (info.data.debugCode) lines.push(`debugCode: ${info.data.debugCode}`);
    if (info.data.debugMessage)
        lines.push(`debugMessage: ${info.data.debugMessage}`);
    if (info.data.details && info.data.details.length > 0)
        lines.push(`details: ${info.data.details.join(' | ')}`);

    return lines.join('\n');
}

/**
 * Authorizes the SDK with the company's Square token and location from the server, unless it already is for this
 * location. The token is never stored by the web page.
 */
export async function ensureReaderAuthorized(
    sessionUrl: string,
): Promise<void> {
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
