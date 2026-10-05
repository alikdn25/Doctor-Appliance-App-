import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { CalendarCheck, Ellipsis, MapPin, MessageSquare } from 'lucide-react';
import { useSidebar } from '@/components/ui/sidebar';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { calendar } from '@/routes';
import { mine as myJobs } from '@/routes/jobs';
import { index as messageInbox } from '@/routes/messages';

type Tab = { key: string; href: string; icon: LucideIcon; badge?: number };

const tabClass =
    'relative flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 rounded-2xl text-xs font-semibold text-[#5B6779]';
const activeClass =
    'text-[#0A6CF5] bg-[linear-gradient(90deg,#F2F7FF,#D2E2F8)] shadow-[inset_0_1px_0_#fff,0_4px_10px_rgba(10,108,245,.22)]';

/**
 * Bottom tabs on phones: My Jobs · Map · Messages · More (approved My Jobs mockup).
 * Tabs the member may not open are left out; More opens the full menu.
 */
export function MobileTabBar() {
    const { auth, unreadMessages } = usePage().props;
    const { url } = usePage();
    const { setOpenMobile } = useSidebar();
    const t = useTrans();
    const can = auth.can ?? {};

    if (!auth.company) return null;

    const tabs = [
        can.viewMyJobs && {
            key: 'today',
            href: myJobs().url,
            icon: CalendarCheck,
        },
        can.viewCalendar && {
            key: 'map',
            href: calendar({ query: { view: 'map' } }).url,
            icon: MapPin,
        },
        can.viewMessageInbox && {
            key: 'messages',
            href: messageInbox().url,
            icon: MessageSquare,
            badge: unreadMessages ?? 0,
        },
    ].filter(Boolean) as Tab[];

    const path = url.split('?')[0];

    return (
        <nav
            aria-label={t('nav.tabs')}
            className="da-tabbar sticky bottom-0 z-20 flex gap-1 border-t border-[#E1E8F2] bg-white px-2 pt-1 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-6px_18px_rgba(16,42,79,.08)] md:hidden"
        >
            {tabs.map((tab) => {
                const active = path === new URL(tab.href, 'http://x').pathname;
                const Icon = tab.icon;
                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(tabClass, active && activeClass)}
                    >
                        <Icon className="size-6" aria-hidden="true" />
                        {t(`nav.tab_${tab.key}`)}
                        {!!tab.badge && (
                            <span className="absolute top-1 left-[56%] flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-[linear-gradient(90deg,#F87171,#B91C1C)] px-1 text-[11px] font-bold text-white">
                                {tab.badge}
                            </span>
                        )}
                    </Link>
                );
            })}
            <button
                type="button"
                className={tabClass}
                onClick={() => setOpenMobile(true)}
            >
                <Ellipsis className="size-6" aria-hidden="true" />
                {t('nav.tab_more')}
            </button>
        </nav>
    );
}
