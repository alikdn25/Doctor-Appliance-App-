import type { ReactNode } from 'react';
import { useCallback } from 'react';
import { usePublishedHeight } from '@/hooks/use-published-height';
import {
    scrollToPageEnd,
    useKeyboardChange,
} from '@/hooks/use-viewport-keyboard';
import { cn } from '@/lib/utils';

/**
 * A composer docked at the bottom of a phone screen (assistant, SMS chat, Quick booking). Without the keyboard it
 * sits right above the bottom bar; while the keyboard is open the bar hides and the composer sits right on top of the
 * keyboard, with no gap (app.css, .da-chat-dock). Opening the keyboard scrolls to the end, so the last message stays
 * in view. On a desktop it is a card kept at the bottom of the window.
 */
export function ChatDock({
    children,
    className,
    scrollOnKeyboard = true,
}: {
    children: ReactNode;
    className?: string;
    /** Scroll to the last message when the keyboard opens. */
    scrollOnKeyboard?: boolean;
}) {
    const dock = usePublishedHeight<HTMLDivElement>('--chat-dock-h');
    const onKeyboard = useCallback(
        (open: boolean) => {
            if (open && scrollOnKeyboard) {
                // Once the screen has finished shrinking.
                window.setTimeout(() => scrollToPageEnd(), 60);
                window.setTimeout(() => scrollToPageEnd(), 300);
            }
        },
        [scrollOnKeyboard],
    );
    useKeyboardChange(onKeyboard);

    return (
        <>
            {/* Room under the last message for the docked composer and the bottom bar. */}
            <div className="da-chat-spacer md:hidden" aria-hidden="true" />
            <div
                ref={dock}
                data-slot="chat-dock"
                className={cn(
                    'da-chat-dock da-action-bar border-t p-3 md:sticky md:bottom-4 md:z-30 md:rounded-2xl md:border',
                    className,
                )}
            >
                {children}
            </div>
        </>
    );
}
