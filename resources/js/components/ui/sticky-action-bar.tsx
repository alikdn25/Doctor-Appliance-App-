import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Actions pinned to the bottom of an inner screen (the next step of a job, Save, Take payment). While it is on
 * screen the bottom bar hides (da-pinned), so nothing covers the actions. On a desktop it stays in the flow.
 */
export function StickyActionBar({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <>
            {/* Room for the bar, so the last content is not hidden under it. */}
            <div className="h-24 md:hidden" aria-hidden="true" />
            <div
                data-slot="sticky-action-bar"
                className={cn(
                    'da-pinned da-action-bar fixed inset-x-0 bottom-0 z-40 flex gap-2 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:static md:z-auto md:border-0 md:bg-transparent md:p-0 md:shadow-none [&>*]:flex-1 md:[&>*]:flex-none',
                    className,
                )}
            >
                {children}
            </div>
        </>
    );
}
