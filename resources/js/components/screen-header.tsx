import type { InertiaLinkProps } from '@inertiajs/react';
import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { BackButton, useBackTarget } from '@/components/back-button';

/** The dark header's slot; a screen with its own header (mockup screens) renders into it. */
export const ScreenHeaderSlot = createContext<HTMLElement | null>(null);

/** Square raised button on the dark header (DESIGN.md "Header buttons"). */
export const headerButtonClass =
    'da-header-btn da-press flex size-10 shrink-0 items-center justify-center';

/**
 * A screen's own header (UX-REDESIGN §4.4, §5.3): ← on child screens (none on root screens, and no menu button —
 * More is in the bottom bar), the title with a short factual subtitle, and buttons on the right.
 */
export function ScreenHeader({
    title,
    subtitle,
    back,
    leading,
    actions,
}: {
    title: string;
    subtitle?: string;
    back?: NonNullable<InertiaLinkProps['href']>;
    /** Replaces ← on the left, e.g. ✕ that closes a form (UX-REDESIGN §7.1). */
    leading?: ReactNode;
    actions?: ReactNode;
}) {
    const slot = useContext(ScreenHeaderSlot);
    const backTo = useBackTarget(back);

    if (!slot) {
        return null;
    }

    return createPortal(
        <div className="flex w-full items-center gap-3">
            {leading ?? (backTo && <BackButton href={backTo} />)}
            <div className="min-w-0 flex-1">
                <h1 className="truncate text-xl leading-tight font-semibold text-white">
                    {title}
                </h1>
                {subtitle && (
                    <p className="line-clamp-2 text-sm text-white/75">
                        {subtitle}
                    </p>
                )}
            </div>
            {actions && <div className="flex shrink-0 gap-2">{actions}</div>}
        </div>,
        slot,
    );
}

/** Whether the page is drawn inside the app layout (with the dark header). */
export function useHasScreenHeader(): boolean {
    return useContext(ScreenHeaderSlot) !== null;
}
