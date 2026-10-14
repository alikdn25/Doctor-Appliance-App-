import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Compact navy screen header (56–64 px, UX-REDESIGN §4.4): ← on child screens only, the title, a short factual
 * subtitle ("4 jobs", "Paid · Oct 7") and one or two raised icon buttons on the right.
 */
export function AppBar({
    title,
    subtitle,
    back,
    actions,
    className,
}: {
    title: string;
    subtitle?: string;
    back?: NonNullable<InertiaLinkProps['href']>;
    actions?: ReactNode;
    className?: string;
}) {
    const t = useTrans();

    return (
        <header
            data-slot="app-bar"
            className={cn(
                'da-header flex min-h-14 items-center gap-2 px-4 py-2 md:min-h-16',
                className,
            )}
        >
            {back && (
                <Link
                    href={back}
                    aria-label={t('ui.back')}
                    className="da-press da-header-btn -ml-1 inline-flex size-10 shrink-0 items-center justify-center"
                >
                    <ArrowLeft className="size-5" />
                </Link>
            )}
            <div className="min-w-0 flex-1">
                <h1 className="truncate text-xl leading-tight font-semibold">
                    {title}
                </h1>
                {subtitle && (
                    <p className="truncate text-sm text-white/75">{subtitle}</p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 items-center gap-2">{actions}</div>
            )}
        </header>
    );
}
