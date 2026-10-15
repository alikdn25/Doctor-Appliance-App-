import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Floating action button above the bottom bar: the screen's main "+" (blue, with the glow) or a secondary one
 * such as + Trip (white). With a label it becomes an extended pill.
 */
export function Fab({
    label,
    icon,
    showLabel = false,
    variant = 'primary',
    className,
    ...props
}: Omit<ComponentProps<'button'>, 'aria-label'> & {
    label: string;
    icon: ReactNode;
    showLabel?: boolean;
    variant?: 'primary' | 'secondary';
}) {
    return (
        <button
            type="button"
            aria-label={label}
            data-slot="fab"
            className={cn(
                'da-press fixed right-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-30 inline-flex h-14 min-w-14 items-center justify-center gap-2 rounded-[18px] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:bottom-6 [&_svg]:size-6',
                variant === 'primary' ? 'da-primary' : 'da-raised text-navy',
                showLabel && 'px-5 text-[15px] font-semibold',
                className,
            )}
            {...props}
        >
            {icon}
            {showLabel && <span>{label}</span>}
        </button>
    );
}
