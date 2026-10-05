import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowLeft, Menu } from 'lucide-react';
import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useSidebar } from '@/components/ui/sidebar';
import { useTrans } from '@/lib/i18n';

/** The dark header's slot; a screen with its own header (mockup screens) renders into it. */
export const ScreenHeaderSlot = createContext<HTMLElement | null>(null);

/** Square raised button on the dark header (DESIGN.md "Header buttons"). */
export const headerButtonClass =
    'da-header-btn da-press flex size-12 shrink-0 items-center justify-center';

/**
 * A screen's own header, as on the approved mockups: menu or back arrow, title with a subtitle,
 * and buttons on the right (search, filters, more).
 */
export function ScreenHeader({
    title,
    subtitle,
    back,
    actions,
    own = true,
}: {
    title: string;
    subtitle?: string;
    back?: NonNullable<InertiaLinkProps['href']>;
    actions?: ReactNode;
    /** Mockup screens with their own header also hide the unfinished-jobs bar. */
    own?: boolean;
}) {
    const slot = useContext(ScreenHeaderSlot);
    const { toggleSidebar } = useSidebar();
    const t = useTrans();

    if (!slot) {
        return null;
    }

    return createPortal(
        <div
            className={`flex w-full items-center gap-3 ${own ? 'da-screen-own' : ''}`}
        >
            {back ? (
                <Link
                    href={back}
                    aria-label={t('common.back')}
                    className={headerButtonClass}
                >
                    <ArrowLeft className="size-6" aria-hidden="true" />
                </Link>
            ) : (
                <button
                    type="button"
                    aria-label={t('nav.menu')}
                    className="flex size-12 shrink-0 items-center justify-center rounded-xl text-white md:hidden"
                    onClick={toggleSidebar}
                >
                    <Menu className="size-7" aria-hidden="true" />
                </button>
            )}
            <div className="min-w-0 flex-1">
                <h1 className="truncate text-2xl leading-tight font-bold text-white">
                    {title}
                </h1>
                {subtitle && (
                    <p className="line-clamp-2 text-[15px] text-white/80">
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
