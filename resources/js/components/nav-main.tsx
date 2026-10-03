import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTrans } from '@/lib/i18n';
import type { NavItem } from '@/types';

export function NavMain({ items, label }: { items: NavItem[]; label: string }) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const t = useTrans();

    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-3 py-1">
            <SidebarGroupLabel className="px-3 text-[11px] font-semibold tracking-[0.14em] text-sidebar-foreground/45 uppercase">
                {t(label)}
            </SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentOrParentUrl(item.href)}
                            tooltip={{ children: t(item.title) }}
                            size="lg"
                            className="h-11 rounded-xl px-3 text-[14px] font-medium data-[active=true]:bg-primary data-[active=true]:text-primary-foreground data-[active=true]:shadow-sm md:h-11 [&>svg]:size-[18px]"
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{t(item.title)}</span>
                                {!!item.badge && (
                                    <span
                                        className="ml-auto rounded-full bg-primary/15 px-2 py-0.5 text-xs tabular-nums group-data-[collapsible=icon]:hidden"
                                        aria-label={t(
                                            'messages.inbox.unread_count',
                                            { count: item.badge },
                                        )}
                                    >
                                        {item.badge}
                                    </span>
                                )}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
