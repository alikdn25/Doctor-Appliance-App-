import { router } from '@inertiajs/react';

/**
 * "New version available" (owner's task, October 24): the app compares the version it was loaded with to the
 * server's on opening, on coming back to the screen and every 30 minutes. "Update" loads the new version.
 *
 * A form with unsaved changes holds the banner back: any typing in a form or a sheet marks it unsaved until it is
 * saved (a request that is not a GET succeeds), the page changes, or the form or sheet is closed.
 */
export const CHECK_EVERY_MS = 30 * 60 * 1000;

const edited = new Set<Element>();
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((listener) => listener());

function onEdit(event: Event) {
    const target = event.target;
    if (
        !(target instanceof HTMLInputElement) &&
        !(target instanceof HTMLTextAreaElement) &&
        !(target instanceof HTMLSelectElement) &&
        !(target instanceof HTMLElement && target.isContentEditable)
    ) {
        return;
    }
    // Search boxes and filters (date pickers, chips) are not unsaved work.
    if (
        (target instanceof HTMLInputElement && target.type === 'search') ||
        target.closest('[data-update-ignore]')
    ) {
        return;
    }
    const form = target.closest('form, [role="dialog"]');
    if (form && !edited.has(form)) {
        edited.add(form);
        notify();
    }
}

function clear() {
    if (edited.size > 0) {
        edited.clear();
        notify();
    }
}

let started = false;
let lastMethod = 'get';
let lastPath = '';

/** Starts watching for unsaved forms (once per page load). */
export function watchUnsavedForms(): void {
    if (started || typeof document === 'undefined') return;
    started = true;
    lastPath = window.location.pathname;
    document.addEventListener('input', onEdit, true);
    document.addEventListener('change', onEdit, true);
    router.on('start', (event) => {
        lastMethod = event.detail.visit.method;
    });
    // Saved: a form was sent and the server accepted it.
    router.on('success', () => {
        if (lastMethod !== 'get') clear();
    });
    // Another page: what was on the old one is gone.
    router.on('navigate', () => {
        if (window.location.pathname !== lastPath) {
            lastPath = window.location.pathname;
            clear();
        }
    });
}

/** A form or sheet with changes is still on screen. */
export function hasUnsavedForms(): boolean {
    for (const form of edited) {
        if (!form.isConnected) edited.delete(form);
    }

    return edited.size > 0;
}

export function onUnsavedFormsChange(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/** The server's version, or null when it cannot be reached (weak signal). */
export async function fetchServerVersion(): Promise<string | null> {
    try {
        const response = await fetch('/app-version', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return null;
        const data = (await response.json()) as { version?: unknown };

        return typeof data.version === 'string' ? data.version : null;
    } catch {
        return null;
    }
}

/** Loads the new version: refreshes the installed app's service worker, then reloads the page. */
export async function updateApp(): Promise<void> {
    try {
        const registration =
            'serviceWorker' in navigator
                ? await navigator.serviceWorker.getRegistration('/')
                : undefined;
        await registration?.update();
    } catch {
        // The reload below still brings the new pages and files.
    }
    window.location.reload();
}
