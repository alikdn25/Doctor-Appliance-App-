import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { sendJson } from '@/lib/http';

export type AutosaveState = 'idle' | 'saving' | 'saved' | 'error';

/**
 * Saves a text field by itself a moment after typing stops (UX-REDESIGN §6.2: Work done, debounce 1 s), and once
 * more when the page is left with changes not yet saved. Returns the state for a "Saving… / Saved" label.
 */
export function useAutosave(
    value: string,
    url: string,
    field: string,
    delay = 1000,
): AutosaveState {
    const [state, setState] = useState<AutosaveState>('idle');
    // Weak signal: a failed save is tried again a few seconds later.
    const [attempt, setAttempt] = useState(0);
    const saved = useRef(value);
    const latest = useRef(value);
    latest.current = value;

    useEffect(() => {
        if (value === saved.current) {
            return;
        }

        const timer = setTimeout(() => {
            const sending = value;
            setState('saving');
            sendJson(url, 'PUT', { [field]: sending })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }
                    saved.current = sending;
                    setState(latest.current === sending ? 'saved' : 'idle');
                })
                .catch(() => {
                    setState('error');
                    setTimeout(() => setAttempt((n) => n + 1), 5000);
                });
        }, delay);

        return () => clearTimeout(timer);
    }, [value, url, field, delay, attempt]);

    // Leaving the page (another screen, closing the tab) sends what is not saved yet.
    useEffect(() => {
        const flush = () => {
            if (latest.current !== saved.current) {
                saved.current = latest.current;
                void sendJson(
                    url,
                    'PUT',
                    { [field]: latest.current },
                    { keepalive: true },
                ).catch(() => undefined);
            }
        };
        const off = router.on('before', (event) => {
            if (event.detail.visit.url.pathname !== window.location.pathname) {
                flush();
            }
        });
        window.addEventListener('pagehide', flush);

        return () => {
            off();
            window.removeEventListener('pagehide', flush);
        };
    }, [url, field]);

    return state;
}
