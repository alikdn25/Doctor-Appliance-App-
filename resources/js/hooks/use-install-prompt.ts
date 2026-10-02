import { useEffect, useState } from 'react';

type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

let deferred: InstallPromptEvent | null = null;
const listeners = new Set<() => void>();

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (event) => {
        // Show our own "Install app" button instead of the browser's mini bar.
        event.preventDefault();
        deferred = event as InstallPromptEvent;
        listeners.forEach((l) => l());
    });
    window.addEventListener('appinstalled', () => {
        deferred = null;
        listeners.forEach((l) => l());
    });
}

/**
 * "Install app" on Android/Chrome. Null when the app is installed already or the browser
 * cannot install it (iPhone: Share → Add to Home Screen).
 */
export function useInstallPrompt(): (() => Promise<void>) | null {
    const [, setTick] = useState(0);

    useEffect(() => {
        const update = () => setTick((t) => t + 1);
        listeners.add(update);

        return () => {
            listeners.delete(update);
        };
    }, []);

    if (!deferred) {
        return null;
    }

    return async () => {
        const event = deferred;

        if (!event) {
            return;
        }

        await event.prompt();
        await event.userChoice;
        deferred = null;
        listeners.forEach((l) => l());
    };
}
