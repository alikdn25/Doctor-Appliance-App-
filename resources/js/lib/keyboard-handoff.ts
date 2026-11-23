/**
 * Opening a screen with the keyboard already up (Assistant from the "+" menu). Phones open the keyboard only for a
 * focus made during the tap itself, so the tap focuses a hidden input at once; the next screen moves the focus to its
 * own field, and the keyboard stays open.
 */
const HOLDER_ID = 'da-keyboard-holder';

/** Call inside the tap handler, before navigating. */
export function holdKeyboard(): void {
    if (typeof document === 'undefined') return;
    document.getElementById(HOLDER_ID)?.remove();

    const holder = document.createElement('input');
    holder.id = HOLDER_ID;
    holder.type = 'text';
    holder.setAttribute('aria-hidden', 'true');
    holder.tabIndex = -1;
    // 16 px, so iOS does not zoom; invisible and out of the way.
    holder.style.cssText =
        'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;border:0;padding:0;pointer-events:none;';
    document.body.append(holder);
    holder.focus();
    // If the next screen never takes the focus, the holder does not linger.
    window.setTimeout(() => holder.remove(), 5000);
}

/** The next screen's field takes the focus (and the open keyboard) from the hidden input. */
export function takeKeyboard(field: HTMLElement | null): void {
    field?.focus({ preventScroll: true });
    document.getElementById(HOLDER_ID)?.remove();
}
