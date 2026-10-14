import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** A titled block of a screen: 16/600 heading, an optional action on the right, then the content. */
export function Section({
    title,
    action,
    children,
    className,
    id,
}: {
    title: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    id?: string;
}) {
    return (
        <section className={cn('space-y-3', className)} aria-labelledby={id} data-slot="section">
            <div className="flex min-h-10 items-center justify-between gap-3">
                <h2 id={id} className="text-base font-semibold">
                    {title}
                </h2>
                {action}
            </div>
            {children}
        </section>
    );
}
