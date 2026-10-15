import { router, usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { createContext, useContext } from 'react';
import { navPath, useAppNavigation } from '@/hooks/use-app-navigation';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Href = NonNullable<InertiaLinkProps['href']>;

/** The page's breadcrumbs, from the layout: the parent of a child screen is the one before the last. */
export const BreadcrumbsContext = createContext<BreadcrumbItem[]>([]);

const toUrl = (href: Href) => (typeof href === 'string' ? href : href.url);

// Pages seen in this tab (the first load counts): with two or more, the previous history entry is this app's.
let appVisits = 0;

if (typeof window !== 'undefined') {
    router.on('navigate', () => {
        appVisits++;
    });
}

/**
 * Where ← leads on this screen (UX-REDESIGN §5.3): nothing on root screens (the bottom bar's); otherwise the
 * explicit target, the parent from the breadcrumbs, or home.
 */
export function useBackTarget(explicit?: Href): Href | null {
    const crumbs = useContext(BreadcrumbsContext);
    const { rootPaths, home } = useAppNavigation();
    const path = navPath(usePage().url);

    if (explicit) {
        return explicit;
    }

    if (rootPaths.includes(path)) {
        return null;
    }

    const parent = [...crumbs]
        .reverse()
        .find((crumb) => navPath(crumb.href) !== path);

    return parent?.href ?? home;
}

/**
 * ← on a child screen: back through the browser history when the previous page is this app's, otherwise to the
 * parent screen (a link opened from a message or a bookmark).
 */
export function BackButton({
    href,
    className,
}: {
    href: Href;
    className?: string;
}) {
    const t = useTrans();

    const goBack = () => {
        if (appVisits > 1) {
            window.history.back();
        } else {
            router.visit(toUrl(href));
        }
    };

    return (
        <button
            type="button"
            onClick={goBack}
            aria-label={t('nav.back')}
            data-test="back-button"
            className={cn(
                'da-header-btn da-press flex size-10 shrink-0 items-center justify-center',
                className,
            )}
        >
            <ArrowLeft className="size-5" aria-hidden="true" />
        </button>
    );
}
