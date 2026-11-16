import { useEffect } from 'react';

const KEYBOARD_EVENT = 'app:keyboard';

const textField =
    'textarea, [contenteditable="true"], input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="file"]):not([type="range"]):not([type="color"])';

/** Less than this is browser bars appearing and hiding, not a keyboard. */
const KEYBOARD_MIN = 120;

/**
 * The visible screen and the phone keyboard, for the whole app (mounted once in the layout).
 *
 * - `--app-vvh` on <html>: the visible height (window.visualViewport), used instead of 100vh.
 * - `--app-kb-inset`: how much of the window the keyboard covers when it overlays the page (iOS). With
 *   `interactive-widget=resizes-content` (Chrome on Android, installed app) the window itself shrinks and this is 0.
 * - `data-keyboard="open"` on <html> while a text field is focused and the screen has shrunk: the bottom bar and the
 *   "+" hide, and chat composers sit right on top of the keyboard.
 * - An `app:keyboard` event when the keyboard opens or closes (screens scroll their chat to the last message).
 */
export function useViewportKeyboard() {
    useEffect(() => {
        const root = document.documentElement;
        const viewport = window.visualViewport;
        // Tallest visible height seen per window width: the screen without a keyboard (width stays when it opens).
        const tallest = new Map<number, number>();
        let open = false;
        let frame = 0;

        const measure = () => {
            frame = 0;
            const height = viewport?.height ?? window.innerHeight;
            const width = Math.round(window.innerWidth);
            const full = Math.max(
                tallest.get(width) ?? 0,
                window.innerHeight,
                height,
            );
            tallest.set(width, full);

            const overlay = viewport
                ? Math.max(
                      0,
                      Math.round(
                          window.innerHeight - height - viewport.offsetTop,
                      ),
                  )
                : 0;
            const active = document.activeElement;
            const typing =
                active instanceof HTMLElement && active.matches(textField);
            const nowOpen = typing && full - height > KEYBOARD_MIN;

            root.style.setProperty('--app-vvh', `${Math.round(height)}px`);
            root.style.setProperty(
                '--app-kb-inset',
                `${nowOpen && overlay > KEYBOARD_MIN ? overlay : 0}px`,
            );

            if (nowOpen !== open) {
                open = nowOpen;
                if (open) {
                    root.dataset.keyboard = 'open';
                } else {
                    delete root.dataset.keyboard;
                }
                window.dispatchEvent(
                    new CustomEvent(KEYBOARD_EVENT, { detail: { open } }),
                );
            }
        };
        const schedule = () => {
            if (frame === 0) frame = window.requestAnimationFrame(measure);
        };
        // Focus leaves before the keyboard has gone; check again once it has.
        const onFocus = () => {
            schedule();
            window.setTimeout(schedule, 350);
        };

        measure();
        viewport?.addEventListener('resize', schedule);
        viewport?.addEventListener('scroll', schedule);
        window.addEventListener('resize', schedule);
        document.addEventListener('focusin', onFocus);
        document.addEventListener('focusout', onFocus);

        return () => {
            window.cancelAnimationFrame(frame);
            viewport?.removeEventListener('resize', schedule);
            viewport?.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            document.removeEventListener('focusin', onFocus);
            document.removeEventListener('focusout', onFocus);
            delete root.dataset.keyboard;
            root.style.removeProperty('--app-vvh');
            root.style.removeProperty('--app-kb-inset');
        };
    }, []);
}

/** Calls back when the phone keyboard opens (true) or closes (false). */
export function useKeyboardChange(onChange: (open: boolean) => void) {
    useEffect(() => {
        const listener = (event: Event) =>
            onChange(
                Boolean((event as CustomEvent<{ open: boolean }>).detail?.open),
            );
        window.addEventListener(KEYBOARD_EVENT, listener);

        return () => window.removeEventListener(KEYBOARD_EVENT, listener);
    }, [onChange]);
}

/** Scrolls the page to its very end (the last chat message above the composer). */
export function scrollToPageEnd(smooth = false) {
    window.scrollTo({
        top: document.documentElement.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
    });
}
