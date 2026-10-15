import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type BottomNavItem = {
    key: string;
    label: string;
    icon: LucideIcon;
    /** A link, or a button when onClick is given (More). */
    href?: NonNullable<InertiaLinkProps['href']>;
    onClick?: () => void;
    active?: boolean;
    /** The raised blue "+ Book" in the middle. */
    primary?: boolean;
    badge?: number;
};

/**
 * Phone bottom bar (UX-REDESIGN §5, §22): up to five items, the active one always highlighted, an optional raised
 * blue centre button. Hidden on inner screens that pin their own actions (da-pinned).
 */
export function BottomNav({
    items,
    label,
    inline = false,
    className,
}: {
    items: BottomNavItem[];
    label: string;
    /** Shown in the page flow (the UI kit page) instead of fixed to the bottom. */
    inline?: boolean;
    className?: string;
}) {
    return (
        <nav
            aria-label={label}
            data-slot="bottom-nav"
            className={cn(
                'flex bg-white shadow-[0_-6px_18px_rgb(16_42_79/0.08)]',
                inline
                    ? 'rounded-2xl'
                    : 'da-tabbar fixed inset-x-0 bottom-0 z-40 pb-[env(safe-area-inset-bottom)] md:hidden',
                className,
            )}
        >
            {items.map((item) => {
                const classes = cn(
                    'flex min-h-16 flex-1 flex-col items-center justify-center gap-1 text-xs font-medium',
                    item.active ? 'text-[#0050d0]' : 'text-muted-foreground',
                );
                const body = (
                    <>
                        <span
                            className={cn(
                                'relative inline-flex h-8 w-12 items-center justify-center rounded-xl [&_svg]:size-5',
                                item.primary && 'da-primary h-10 w-12 rounded-[14px]',
                                item.active && !item.primary && 'da-soft',
                            )}
                        >
                            <item.icon aria-hidden="true" />
                            {item.badge !== undefined && item.badge > 0 && (
                                <span className="absolute -top-1 -right-1 min-w-[18px] rounded-full bg-danger px-1 text-center text-[11px] leading-[18px] font-semibold text-white">
                                    {item.badge > 99 ? '99+' : item.badge}
                                </span>
                            )}
                        </span>
                        {item.label}
                    </>
                );

                return item.href && !item.onClick ? (
                    <Link
                        key={item.key}
                        href={item.href}
                        aria-current={item.active ? 'page' : undefined}
                        className={classes}
                        data-test={`bottom-nav-${item.key}`}
                    >
                        {body}
                    </Link>
                ) : (
                    <button
                        key={item.key}
                        type="button"
                        onClick={item.onClick}
                        aria-expanded={item.active}
                        className={classes}
                        data-test={`bottom-nav-${item.key}`}
                    >
                        {body}
                    </button>
                );
            })}
        </nav>
    );
}
