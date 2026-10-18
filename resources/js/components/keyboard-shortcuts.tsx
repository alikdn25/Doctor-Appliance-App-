import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { calendar } from '@/routes';
import { create as createJob } from '@/routes/jobs';

/**
 * Keys for a computer (UX-REDESIGN §13): N — Book customer, / — the search of the screen, T — today in the calendar.
 * Ignored while typing in a field or with Ctrl / ⌘ / Alt held.
 */
export function KeyboardShortcuts() {
    const { auth } = usePage().props;
    const can = auth.can ?? {};

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                event.ctrlKey ||
                event.metaKey ||
                event.altKey ||
                event.defaultPrevented
            ) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target &&
                (target.isContentEditable ||
                    ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
            ) {
                return;
            }

            // A dialog or sheet is open: its keys win.
            if (document.querySelector('[role="dialog"]')) {
                return;
            }

            if (event.key === 'n' && can.createJobs) {
                event.preventDefault();
                router.visit(createJob({ query: { book: 1 } }));
            } else if (event.key === 't' && can.viewCalendar) {
                event.preventDefault();
                router.visit(calendar());
            } else if (event.key === '/') {
                const search = document.querySelector<HTMLInputElement>(
                    'input[type="search"]',
                );

                if (search) {
                    event.preventDefault();
                    search.focus();
                }
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [can.createJobs, can.viewCalendar]);

    return null;
}
