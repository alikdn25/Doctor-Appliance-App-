import type { ReactNode } from 'react';
import { usePublishedHeight } from '@/hooks/use-published-height';
import { cn } from '@/lib/utils';

/**
 * Actions pinned to the bottom of an inner screen (the next step of a job, Save, Take payment). While it is on
 * screen the bottom bar hides (da-pinned), so nothing covers the actions. On a desktop it is a card that stays at
 * the bottom of the window while the page scrolls.
 */
export function StickyActionBar({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    // A side column kept in view leaves room for the bar under it (desktop).
    const bar = usePublishedHeight<HTMLDivElement>('--app-action-bar-h');

    return (
        <>
            {/* Room for the bar, so the last content is not hidden under it. */}
            <div className="h-24 md:hidden" aria-hidden="true" />
            <div
                ref={bar}
                data-slot="sticky-action-bar"
                className={cn(
                    'da-pinned da-action-bar fixed inset-x-0 bottom-0 z-40 flex gap-2 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:sticky md:bottom-4 md:z-30 md:mx-auto md:w-[calc(100%-3rem)] md:max-w-3xl md:rounded-2xl md:border md:p-3 md:shadow-[0_10px_24px_-8px_rgb(16_42_79/0.25)] [&>*]:flex-1 md:[&>*]:flex-none',
                    className,
                )}
            >
                {children}
            </div>
        </>
    );
}
