import { Slot } from '@radix-ui/react-slot';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * A square button with only an icon: a 44×44 touch target, the label read out by screen readers (aria-label is
 * required). "raised" for actions on light surfaces (Navigate, Call, Text), "header" on the navy AppBar, "plain"
 * for quiet actions. A number shows a red counter in the corner.
 */
export function IconButton({
    label,
    variant = 'raised',
    count,
    asChild = false,
    className,
    children,
    ...props
}: Omit<ComponentProps<'button'>, 'aria-label'> & {
    label: string;
    variant?: 'raised' | 'header' | 'plain' | 'primary';
    count?: number;
    asChild?: boolean;
}) {
    const Comp = asChild ? Slot : 'button';

    return (
        <Comp
            aria-label={label}
            title={label}
            data-slot="icon-button"
            className={cn(
                'da-press relative inline-flex size-11 shrink-0 items-center justify-center rounded-[14px] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-5 [&_svg]:shrink-0',
                variant === 'raised' && 'da-soft text-navy',
                variant === 'header' && 'da-header-btn size-10',
                variant === 'plain' && 'text-foreground hover:bg-black/5',
                variant === 'primary' && 'da-primary',
                className,
            )}
            {...(asChild ? {} : { type: 'button' as const })}
            {...props}
        >
            {asChild ? (
                children
            ) : (
                <>
                    {children}
                    {count !== undefined && count > 0 && (
                        <span className="absolute -top-1 -right-1 min-w-[18px] rounded-full bg-danger px-1 text-center text-[11px] leading-[18px] font-semibold text-white">
                            {count > 99 ? '99+' : count}
                        </span>
                    )}
                </>
            )}
        </Comp>
    );
}
