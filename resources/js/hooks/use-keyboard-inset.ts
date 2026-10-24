import { useEffect, useState } from 'react';
import type { RefObject } from 'react';

/** How much of the window the on-screen keyboard covers: its height and the visible height above it. */
export type KeyboardInset = { bottom: number; height: number };

const fieldSelector = 'input, textarea, select, [contenteditable="true"]';

/**
 * The phone keyboard (owner's update, October 24): while it is open the sheet sits on top of it, no taller than the
 * visible part of the screen, and the field being typed in scrolls into view. Uses the visual viewport, which shrinks
 * when the keyboard opens; desktop browsers report no inset.
 */
export function useKeyboardInset(
    ref: RefObject<HTMLElement | null>,
    enabled = true,
): KeyboardInset | null {
    const [inset, setInset] = useState<KeyboardInset | null>(null);

    useEffect(() => {
        const viewport = window.visualViewport;
        if (!enabled || !viewport) return;

        let timer: number | undefined;
        const reveal = () => {
            window.clearTimeout(timer);
            // After the keyboard has finished opening and the sheet has moved.
            timer = window.setTimeout(() => {
                const active = document.activeElement;
                if (
                    active instanceof HTMLElement &&
                    ref.current?.contains(active) &&
                    active.matches(fieldSelector)
                ) {
                    active.scrollIntoView({ block: 'center' });
                }
            }, 150);
        };
        const measure = () => {
            const bottom = Math.max(
                0,
                Math.round(
                    window.innerHeight - viewport.height - viewport.offsetTop,
                ),
            );
            // Small differences are browser bars, not a keyboard.
            setInset(
                bottom > 80
                    ? { bottom, height: Math.round(viewport.height) }
                    : null,
            );
            reveal();
        };
        const onFocus = (event: FocusEvent) => {
            if (
                event.target instanceof HTMLElement &&
                event.target.matches(fieldSelector)
            ) {
                reveal();
            }
        };

        measure();
        viewport.addEventListener('resize', measure);
        viewport.addEventListener('scroll', measure);
        document.addEventListener('focusin', onFocus);

        return () => {
            window.clearTimeout(timer);
            viewport.removeEventListener('resize', measure);
            viewport.removeEventListener('scroll', measure);
            document.removeEventListener('focusin', onFocus);
        };
    }, [ref, enabled]);

    return enabled ? inset : null;
}
