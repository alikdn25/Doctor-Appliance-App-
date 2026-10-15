import { toast as sonner } from 'sonner';

/**
 * Toasts (UX-REDESIGN §4.8): a short message at the bottom; with an Undo action for things that can be taken
 * back (a deleted job, a moved visit), shown for 6 seconds.
 */
export const toast = {
    success: (message: string) => sonner.success(message),
    error: (message: string) => sonner.error(message),
    info: (message: string) => sonner(message),
    withUndo: (message: string, undoLabel: string, onUndo: () => void) =>
        sonner(message, {
            duration: 6000,
            action: { label: undoLabel, onClick: onUndo },
        }),
};
