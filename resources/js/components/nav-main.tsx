import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTrans } from '@/lib/i18n';
import type { NavItem } from '@/types';

/**
 * A menu group. A collapsible group stays closed until opened, or while one of its pages is open,
 * so the menu shows the everyday sections first.
 */
export function NavMain({
    items,
    label,
    collapsible = false,
}: {
    items: NavItem[];
    label: string;
    collapsible?: boolean;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const t = useTrans();
    const { setOpenMobile, state, isMobile } = useSidebar();
    const hasActive = items.some((item) => isCurrentOrParentUrl(item.href));
    const [open, setOpen] = useState(hasActive);

    if (items.length === 0) {
        return null;
    }

    // An icon-only desktop sidebar has no room for group headings: show every item.
    if (collapsible && (isMobile || state === 'expanded')) {
        return (
            <Collapsible open={open} onOpenChange={setOpen} asChild>
                <SidebarGroup className="px-3 py-1">
                    <CollapsibleTrigger className="flex min-h-11 w-full items-center gap-2 rounded-xl px-3 text-left text-[11px] font-semibold tracking-[0.14em] text-sidebar-foreground/60 uppercase hover:bg-sidebar-accent">
                        <span className="flex-1">{t(label)}</span>
                        <ChevronRight
                            aria-hidden="true"
                            className={`size-4 transition-transform ${open ? 'rotate-90' : ''}`}
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <NavItems
                            items={items}
                            isCurrentOrParentUrl={isCurrentOrParentUrl}
                            onNavigate={() => setOpenMobile(false)}
                        />
                    </CollapsibleContent>
                </SidebarGroup>
            </Collapsible>
        );
    }

    return (
        <SidebarGroup className="px-3 py-1">
            <SidebarGroupLabel className="px-3 text-[11px] font-semibold tracking-[0.14em] text-sidebar-foreground/45 uppercase">
                {t(label)}
            </SidebarGroupLabel>
            <NavItems
                items={items}
                isCurrentOrParentUrl={isCurrentOrParentUrl}
                onNavigate={() => setOpenMobile(false)}
            />
        </SidebarGroup>
    );
}

function NavItems({
    items,
    isCurrentOrParentUrl,
    onNavigate,
}: {
    items: NavItem[];
    isCurrentOrParentUrl: (href: NavItem['href']) => boolean;
    onNavigate: () => void;
}) {
    const t = useTrans();

    return (
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
                        <Link href={item.href} prefetch onClick={onNavigate}>
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
    );
}
