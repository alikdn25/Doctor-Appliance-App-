import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * One row of a list: leading picture or icon, title, one or two lines of facts, something on the right. The whole
 * row is the tap target (min 56 px) when it has an href or onClick. Rows sit inside a Card without their own shadow.
 */
export function ListRow({
    leading,
    title,
    subtitle,
    meta,
    trailing,
    href,
    onClick,
    chevron = false,
    className,
}: {
    leading?: ReactNode;
    title: ReactNode;
    subtitle?: ReactNode;
    meta?: ReactNode;
    trailing?: ReactNode;
    href?: NonNullable<InertiaLinkProps['href']>;
    onClick?: () => void;
    chevron?: boolean;
    className?: string;
}) {
    const body = (
        <>
            {leading && <div className="shrink-0">{leading}</div>}
            <div className="min-w-0 flex-1">
                <div className="truncate text-base font-semibold">{title}</div>
                {subtitle && (
                    <div className="truncate text-sm text-muted-foreground">
                        {subtitle}
                    </div>
                )}
                {meta && <div className="mt-1 flex flex-wrap gap-1.5">{meta}</div>}
            </div>
            {trailing && <div className="shrink-0">{trailing}</div>}
            {chevron && (
                <ChevronRight
                    className="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            )}
        </>
    );
    const classes = cn(
        'flex min-h-14 w-full items-center gap-3 px-3 py-2.5 text-left',
        (href || onClick) && 'hover:bg-white/60 active:bg-white/80',
        className,
    );

    if (href) {
        return (
            <Link href={href} className={classes} data-slot="list-row">
                {body}
            </Link>
        );
    }

    if (onClick) {
        return (
            <button type="button" onClick={onClick} className={classes} data-slot="list-row">
                {body}
            </button>
        );
    }

    return (
        <div className={classes} data-slot="list-row">
            {body}
        </div>
    );
}
