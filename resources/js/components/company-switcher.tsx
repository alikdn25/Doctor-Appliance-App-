import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useTrans } from '@/lib/i18n';
import { switchMethod } from '@/routes/companies';

/**
 * Shows the current company. When the user belongs to several companies,
 * it becomes a dropdown to switch between them.
 */
export function CompanySwitcher() {
    const { auth, impersonation } = usePage().props;
    const t = useTrans();

    if (!auth.company) {
        return null;
    }

    const canSwitch = auth.companies.length > 1 && !impersonation;

    const current = (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <Building2 className="size-4" />
            </div>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold">
                    {auth.company.name}
                </span>
                {auth.role && (
                    <span className="truncate text-xs text-muted-foreground">
                        {auth.role.label}
                    </span>
                )}
            </div>
        </>
    );

    if (!canSwitch) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" className="cursor-default">
                        {current}
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            data-test="company-switcher"
                        >
                            {current}
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                        align="start"
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            {t('nav.switch_company')}
                        </DropdownMenuLabel>
                        {auth.companies.map((company) => (
                            <DropdownMenuItem
                                key={company.id}
                                className="min-h-11 cursor-pointer"
                                onSelect={() =>
                                    company.id !== auth.company?.id &&
                                    router.post(switchMethod(company.id).url)
                                }
                            >
                                <span className="flex-1 truncate">
                                    {company.name}
                                </span>
                                {company.id === auth.company?.id && (
                                    <Check className="size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
