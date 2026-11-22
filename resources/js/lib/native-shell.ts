import { Capacitor, registerPlugin } from '@capacitor/core';

/**
 * The Android app's own small plugin (android/…/AppShellPlugin.kt, owner's task of November 22): the installed
 * version (for "Update available") and opening a link outside the app (the APK download goes to the phone's
 * browser, which saves and installs it). The Android back button is wired here too: it does what the ← arrow on
 * the screen does. Nothing happens in a browser.
 */
type AppShellPlugin = {
    info(): Promise<{ versionCode: number; versionName: string }>;
    openExternal(options: { url: string }): Promise<void>;
};

export const AppShell = registerPlugin<AppShellPlugin>('AppShell');

export function hasAppShell(): boolean {
    try {
        return (
            Capacitor.isNativePlatform() &&
            Capacitor.isPluginAvailable('AppShell')
        );
    } catch {
        return false;
    }
}

/**
 * The Android back button (MainActivity asks this first): closes an open sheet or dialog, else presses the screen's
 * ← arrow. Returns false on a root screen (no arrow): the app then goes to the background, as Android apps do.
 */
export function androidBack(): boolean {
    const open = document.querySelector(
        '[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]',
    );
    if (open) {
        const target = (document.activeElement as HTMLElement | null) ?? open;
        target.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape',
                code: 'Escape',
                bubbles: true,
                cancelable: true,
            }),
        );

        return true;
    }

    const arrows = Array.from(
        document.querySelectorAll<HTMLElement>(
            '[data-test="back-button"], [data-test="take-payment-back"]',
        ),
    ).filter((el) => el.offsetParent !== null);
    if (arrows.length > 0) {
        arrows[arrows.length - 1].click();

        return true;
    }

    return false;
}

export type AndroidRelease = {
    version_code: number;
    version_name: string;
    url: string;
};

/** The APK on the server, when it is newer than the installed app; null otherwise (or in a browser). */
export async function newerAndroidRelease(): Promise<AndroidRelease | null> {
    if (!hasAppShell()) return null;

    try {
        const [installed, response] = await Promise.all([
            AppShell.info(),
            fetch('/download/android.json', {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            }),
        ]);
        if (!response.ok) return null;
        const latest = (await response.json()) as Partial<AndroidRelease>;

        return typeof latest.version_code === 'number' &&
            typeof latest.url === 'string' &&
            latest.version_code > installed.versionCode
            ? (latest as AndroidRelease)
            : null;
    } catch {
        return null;
    }
}

/** Opens the APK download in the phone's browser (it cannot be installed from inside the app's web view). */
export function downloadAndroidRelease(url: string): void {
    void AppShell.openExternal({
        url: new URL(url, window.location.origin).toString(),
    }).catch(() => {
        window.location.href = url;
    });
}

export function initNativeShell(): void {
    if (typeof window === 'undefined' || !hasAppShell()) return;
    (window as unknown as { __daBack?: () => boolean }).__daBack = androidBack;
}
