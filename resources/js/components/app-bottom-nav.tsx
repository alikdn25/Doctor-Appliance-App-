import { usePage } from '@inertiajs/react';
import { Ellipsis } from 'lucide-react';
import { useState } from 'react';
import { MoreSheet } from '@/components/more-sheet';
import { BottomNav } from '@/components/ui/bottom-nav';
import { navPath, useAppNavigation } from '@/hooks/use-app-navigation';
import { useTrans } from '@/lib/i18n';
import { create as bookCustomer } from '@/routes/jobs';

/**
 * Phone bottom bar by role and chosen menu (UX-REDESIGN §5.1, §22). The active item is always highlighted; the
 * centre "+ Book" books on the day the calendar shows; More opens the full menu as a bottom sheet.
 */
export function AppBottomNav() {
    const page = usePage();
    const { bottom } = useAppNavigation();
    const t = useTrans();
    const [moreOpen, setMoreOpen] = useState(false);

    if (bottom.length === 0) {
        return null;
    }

    const path = new URL(page.url, 'http://x').pathname;
    const calendarDay =
        page.component === 'calendar/index' &&
        typeof page.props.date === 'string'
            ? page.props.date
            : null;

    return (
        <>
            <BottomNav
                label={t('nav.tabs')}
                items={[
                    ...bottom.map((item) => ({
                        key: item.key,
                        label: t(item.title),
                        icon: item.icon,
                        href:
                            item.key === 'book' && calendarDay
                                ? bookCustomer({
                                      query: { book: 1, date: calendarDay },
                                  })
                                : item.href,
                        primary: item.primary,
                        badge: item.badge,
                        active: !item.primary && navPath(item.href) === path,
                    })),
                    {
                        key: 'more',
                        label: t('nav.more'),
                        icon: Ellipsis,
                        onClick: () => setMoreOpen(true),
                        active: moreOpen,
                    },
                ]}
            />
            <MoreSheet open={moreOpen} onOpenChange={setMoreOpen} />
        </>
    );
}
