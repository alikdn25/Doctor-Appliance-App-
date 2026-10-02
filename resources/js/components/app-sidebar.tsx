import { Link, usePage } from '@inertiajs/react';
import {
    Building,
    Building2,
    ListChecks,
    CalendarDays,
    ClipboardList,
    Contact,
    LayoutGrid,
    Percent,
    Receipt,
    Tags,
    Users,
    Wrench,
} from 'lucide-react';
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
} from '@/components/ui/sidebar';
import { calendar, dashboard } from '@/routes';
import { index as adminCompanies } from '@/routes/admin/companies';
import { index as brands } from '@/routes/brands';
import { index as customers } from '@/routes/customers';
import { edit as checklists } from '@/routes/company/checklists';
import { edit as companySettings } from '@/routes/company/settings';
import { index as invoices } from '@/routes/invoices';
import { index as jobs, mine as myJobs } from '@/routes/jobs';
import { index as taxes } from '@/routes/taxes';
import { index as team } from '@/routes/team';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage().props;
    const can = auth.can ?? {};

    const mainItems: NavItem[] = auth.company
        ? ([
              { title: 'nav.dashboard', href: dashboard(), icon: LayoutGrid },
              can.viewMyJobs && {
                  title: 'nav.my_jobs',
                  href: myJobs(),
                  icon: Wrench,
              },
              can.viewCalendar && {
                  title: 'nav.calendar',
                  href: calendar(),
                  icon: CalendarDays,
              },
              can.viewJobs && {
                  title: 'nav.jobs',
                  href: jobs(),
                  icon: ClipboardList,
              },
              can.viewInvoices && {
                  title: 'nav.invoices',
                  href: invoices(),
                  icon: Receipt,
              },
              can.viewCustomers && {
                  title: 'nav.customers',
                  href: customers(),
                  icon: Contact,
              },
          ].filter(Boolean) as NavItem[])
        : [];

    const companyItems: NavItem[] = [
        can.viewBrands && { title: 'nav.brands', href: brands(), icon: Tags },
        can.manageTeam && { title: 'nav.team', href: team(), icon: Users },
        can.viewTaxes && { title: 'nav.taxes', href: taxes(), icon: Percent },
        can.manageChecklists && {
            title: 'nav.checklists',
            href: checklists(),
            icon: ListChecks,
        },
        can.manageCompany && {
            title: 'nav.company_settings',
            href: companySettings(),
            icon: Building,
        },
    ].filter(Boolean) as NavItem[];

    const adminItems: NavItem[] = auth.user?.is_super_admin
        ? [
              {
                  title: 'nav.companies',
                  href: adminCompanies(),
                  icon: Building2,
              },
          ]
        : [];

    const homeHref = auth.user?.is_super_admin ? adminCompanies() : dashboard();

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
                <NavMain items={mainItems} label="nav.group_main" />
                <NavMain items={companyItems} label="nav.group_company" />
                <NavMain items={adminItems} label="nav.group_platform" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
