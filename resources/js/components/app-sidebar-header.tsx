import { usePage } from '@inertiajs/react';
import { BackButton, useBackTarget } from '@/components/back-button';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { usePublishedHeight } from '@/hooks/use-published-height';
import { useTrans } from '@/lib/i18n';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The compact navy header (UX-REDESIGN §4.4): on a phone ← (child screens only) and the screen's name; on a
 * desktop the breadcrumbs. No menu button: More is in the bottom bar, the sidebar on a desktop.
 * A screen with its own header (ScreenHeader) draws into the slot and replaces this content.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
    slotRef,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    slotRef?: (element: HTMLDivElement | null) => void;
}) {
    const t = useTrans();
    const backTo = useBackTarget();
    const { component } = usePage();
    // Things kept in view while scrolling (a job's side column, table headers) sit just below this header.
    const header = usePublishedHeight<HTMLElement>('--app-header-h');
    const title =
        breadcrumbs.length > 0
            ? t(breadcrumbs[breadcrumbs.length - 1].title)
            : '';

    return (
        <header
            ref={header}
            className="da-header sticky top-[var(--app-bar-h,0px)] z-20 flex min-h-14 shrink-0 items-center justify-between gap-2 px-3 pt-2 pb-6 md:min-h-16 md:px-4"
            data-component={component}
        >
            <div ref={slotRef} className="da-header-slot contents" />
            <div className="da-header-default flex min-w-0 flex-1 items-center gap-3">
                {backTo && <BackButton href={backTo} className="md:hidden" />}
                {/* Not a heading: screens give their own h1; this only names the screen on a phone. */}
                <p className="truncate text-xl font-semibold text-white md:hidden">
                    {title}
                </p>
                <div className="hidden min-w-0 text-white/80 md:block [&_a]:text-white/80 [&_a:hover]:text-white [&_span]:text-white">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
        </header>
    );
}
