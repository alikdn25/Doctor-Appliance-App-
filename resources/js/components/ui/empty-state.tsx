import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** What an empty list says: why it is empty and the one thing to do next. */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            data-slot="empty-state"
            className={cn(
                'flex flex-col items-center gap-3 px-6 py-10 text-center',
                className,
            )}
        >
            {Icon && (
                <span className="da-soft inline-flex size-14 items-center justify-center rounded-2xl">
                    <Icon className="size-7" aria-hidden="true" />
                </span>
            )}
            <p className="text-base font-semibold">{title}</p>
            {description && (
                <p className="max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {action}
        </div>
    );
}
