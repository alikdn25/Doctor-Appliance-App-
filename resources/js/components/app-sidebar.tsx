import { Link, usePage, usePoll } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { CompanySwitcher } from '@/components/company-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { useAppNavigation } from '@/hooks/use-app-navigation';
import type { NavItem } from '@/types';

/**
 * Desktop sidebar (on a phone the same sections are in the bottom bar and More). Built from useAppNavigation, the
 * one source of the menu.
 */
export function AppSidebar() {
    const { auth } = usePage().props;
    const can = auth.can ?? {};
    const { groups, home } = useAppNavigation();
    usePoll(
        30000,
        { only: ['unreadMessages'] },
        { autoStart: Boolean(can.viewMessageInbox) },
    );

    const items = (key: string): NavItem[] =>
        (groups.find((group) => group.key === key)?.items ?? []).map(
            (item) => ({
                title: item.title,
                href: item.href,
                icon: item.icon,
                badge: item.badge,
            }),
        );
    const homeHref = home;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                {auth.company ? (
                    <CompanySwitcher />
                ) : (
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href={homeHref} prefetch>
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                )}
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items('main')} label="nav.group_main" />
                <NavMain
                    items={items('money')}
                    label="nav.group_money"
                    collapsible
                />
                <NavMain
                    items={items('company')}
                    label="nav.group_company"
                    collapsible
                />
                <NavMain items={items('platform')} label="nav.group_platform" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
            {/* Desktop: the sidebar folds from its edge; the header has no second menu button. */}
            <SidebarRail />
        </Sidebar>
    );
}
