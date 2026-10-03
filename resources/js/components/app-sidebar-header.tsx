import { Link, usePage } from '@inertiajs/react';
import { Menu, Plus } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
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
        <header className="sticky top-0 z-20 flex min-h-16 shrink-0 items-center justify-between gap-2 border-b bg-background px-3 py-2 md:px-4">
            <div className="flex min-w-0 items-center gap-3">
                <Button type="button" variant="outline" className="h-11 shrink-0 px-4" onClick={toggleSidebar} aria-label={t('nav.menu')} aria-expanded={isMobile ? openMobile : state === 'expanded'}>
                    <Menu aria-hidden="true" /> {t('nav.menu')}
                </Button>
                <div className="hidden min-w-0 sm:block"><Breadcrumbs breadcrumbs={breadcrumbs} /></div>
            </div>
            {auth.company && auth.can.createJobs && <Button asChild className="h-11 shrink-0"><Link href={create({ query: { book: 1 } })}><Plus aria-hidden="true" />{t('nav.book_customer')}</Link></Button>}
        </header>
    );
}
