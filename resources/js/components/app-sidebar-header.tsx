import { Link, usePage } from '@inertiajs/react';
import { Menu, Plus } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { useSidebar } from '@/components/ui/sidebar';
import { useTrans } from '@/lib/i18n';
import { create } from '@/routes/jobs';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { toggleSidebar, openMobile, state, isMobile } = useSidebar();
    const { auth } = usePage().props;
    const t = useTrans();
    return (
        <header className="da-header sticky top-0 z-20 flex min-h-16 shrink-0 items-center justify-between gap-2 px-3 pt-2 pb-6 md:px-4">
            <div className="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    className="da-header-btn da-press flex h-11 shrink-0 items-center gap-2 px-4 text-sm font-semibold"
                    onClick={toggleSidebar}
                    aria-label={t('nav.menu')}
                    aria-expanded={isMobile ? openMobile : state === 'expanded'}
                >
                    <Menu className="size-5" aria-hidden="true" />{' '}
                    {t('nav.menu')}
                </button>
                <div className="hidden min-w-0 text-white/80 sm:block [&_a]:text-white/80 [&_a:hover]:text-white [&_span]:text-white">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
            {auth.company && auth.can.createJobs && (
                <Link
                    href={create({ query: { book: 1 } })}
                    className="da-raised da-press flex h-11 shrink-0 items-center gap-2 rounded-2xl px-4 text-sm font-semibold text-[#0E2A4F]"
                >
                    <Plus className="size-4" aria-hidden="true" />
                    {t('nav.book_customer')}
                </Link>
            )}
        </header>
    );
}
