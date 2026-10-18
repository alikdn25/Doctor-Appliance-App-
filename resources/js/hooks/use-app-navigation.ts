import { usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Banknote,
    BarChart3,
    BookOpen,
    Bot,
    Building,
    Building2,
    CalendarCheck,
    CalendarDays,
    Car,
    ClipboardList,
    Contact,
    History,
    LayoutGrid,
    MapPin,
    MessageSquare,
    Percent,
    Plus,
    Receipt,
    Star,
    Tags,
    Trash2,
    Users,
    Wallet,
} from 'lucide-react';
import { calendar, dashboard, expensesHub } from '@/routes';
import { index as adminCompanies } from '@/routes/admin/companies';
import { index as brands } from '@/routes/brands';
import { index as cash } from '@/routes/cash';
import { bookingBot, changeLog } from '@/routes/company';
import { edit as googleProfiles } from '@/routes/company/google-profiles';
import { edit as messaging } from '@/routes/company/messaging';
import { edit as services } from '@/routes/company/services';
import { edit as companySettings } from '@/routes/company/settings';
import { index as customers } from '@/routes/customers';
import { index as expenses } from '@/routes/expenses';
import { index as invoices } from '@/routes/invoices';
import {
    create as bookCustomer,
    index as jobs,
    mine as myJobs,
} from '@/routes/jobs';
import { index as messageInbox } from '@/routes/messages';
import { index as reports } from '@/routes/reports';
import { index as taxes } from '@/routes/taxes';
import { index as team } from '@/routes/team';
import { index as trash } from '@/routes/trash';
import { index as trips } from '@/routes/trips';

type Href = NonNullable<InertiaLinkProps['href']>;

export type AppNavItem = {
    key: string;
    /** Translation key. */
    title: string;
    href: Href;
    icon: LucideIcon;
    badge?: number;
};

export type AppNavGroup = {
    key: 'main' | 'money' | 'company' | 'platform';
    label: string;
    items: AppNavItem[];
};

export type AppNavigation = {
    /** Phone bottom bar (UX-REDESIGN §5.1, §22), without More. */
    bottom: (AppNavItem & { primary?: boolean })[];
    /** Every section, in groups: the desktop sidebar and the More sheet. */
    groups: AppNavGroup[];
    /** Root screens (the bottom bar's): no back arrow. */
    rootPaths: string[];
    home: Href;
    /** Automatic texting: the inbox is a header icon on Today and Jobs instead of a More item. */
    inboxInHeader: boolean;
    messages: AppNavItem | null;
};

const toUrl = (href: Href) => (typeof href === 'string' ? href : href.url);
const pathOf = (href: Href) => new URL(toUrl(href), 'http://x').pathname;

/**
 * The app's navigation, from the person's permissions, role and chosen menu (Field / Office). One source for the
 * bottom bar, the More sheet and the desktop sidebar, so they never disagree.
 */
export function useAppNavigation(): AppNavigation {
    const { auth, unreadMessages } = usePage().props;
    const pageUrl = usePage().url;
    // On the calendar, + Book books on the day it shows (UX-REDESIGN §9.1).
    const shownDay = pageUrl.startsWith('/calendar')
        ? new URL(pageUrl, 'http://x').searchParams.get('date')
        : null;
    const can = auth.can ?? {};
    const role = auth.role?.value;
    const isOwner = role === 'owner';
    const office = auth.user?.menu_mode === 'office';
    const smsAutomatic = auth.company?.sms_mode === 'automatic';
    const item = (
        key: string,
        title: string,
        href: Href,
        icon: LucideIcon,
        badge?: number,
    ): AppNavItem => ({ key, title, href, icon, badge });

    const book =
        can.createJobs &&
        item(
            'book',
            'nav.book_customer',
            bookCustomer({
                query: shownDay ? { book: 1, date: shownDay } : { book: 1 },
            }),
            Plus,
        );
    const today =
        can.viewMyJobs && item('today', 'nav.today', myJobs(), CalendarCheck);
    const calendarItem =
        can.viewCalendar &&
        item('calendar', 'nav.calendar', calendar(), CalendarDays);
    const map =
        can.viewCalendar &&
        item('map', 'nav.map', calendar({ query: { view: 'map' } }), MapPin);
    const jobsItem =
        can.viewJobs && item('jobs', 'nav.jobs', jobs(), ClipboardList);
    const invoicesItem =
        can.viewInvoices &&
        item('invoices', 'nav.invoices', invoices(), Receipt);
    const messages =
        can.viewMessageInbox &&
        item(
            'messages',
            'nav.sms_inbox',
            messageInbox(),
            MessageSquare,
            unreadMessages ?? 0,
        );
    const mileage =
        can.viewBusinessExpenses &&
        item('mileage', 'nav.mileage', trips(), Car);
    const myExpenses =
        can.viewBusinessExpenses &&
        item('my-expenses', 'nav.my_expenses', expenses(), Wallet);

    // Technicians (and anyone without the office screens) work in the field.
    const fieldWorker = !can.viewJobs && !can.viewCalendar;

    const bottom = (
        fieldWorker
            ? [today, mileage, myExpenses]
            : [
                  today,
                  calendarItem,
                  book && { ...book, title: 'nav.book', primary: true },
                  office ? jobsItem : invoicesItem,
              ]
    ).filter(Boolean) as AppNavigation['bottom'];

    const main = [
        book,
        today && { ...today, title: 'nav.my_jobs' },
        calendarItem,
        map,
        jobsItem,
        can.viewCustomers &&
            item('customers', 'nav.customers', customers(), Contact),
        invoicesItem,
        // With automatic texting the inbox sits in the header of Today and Jobs as well.
        messages,
    ].filter(Boolean) as AppNavItem[];

    const money = (
        isOwner
            ? [
                  can.viewInvoices &&
                      item('reports', 'nav.reports', reports(), BarChart3),
                  item(
                      'expenses-hub',
                      'nav.expenses_and_taxes',
                      expensesHub(),
                      Wallet,
                  ),
              ]
            : [
                  can.viewInvoices &&
                      item('reports', 'nav.reports', reports(), BarChart3),
                  can.viewInvoices &&
                      item('cash', 'nav.cash', cash(), Banknote),
                  fieldWorker
                      ? myExpenses
                      : can.viewBusinessExpenses &&
                        item(
                            'expenses',
                            'nav.business_expenses',
                            expenses(),
                            Wallet,
                        ),
                  mileage,
              ]
    ).filter(Boolean) as AppNavItem[];

    const company = [
        auth.company &&
            item(
                'getting-started',
                'nav.getting_started',
                dashboard(),
                LayoutGrid,
            ),
        can.viewBrands && item('brands', 'nav.brands', brands(), Tags),
        can.manageTeam && item('team', 'nav.team', team(), Users),
        can.viewTaxes && item('taxes', 'nav.taxes', taxes(), Percent),
        can.manageCatalog &&
            item('services', 'nav.services', services(), BookOpen),
        can.manageCatalog &&
            item(
                'google-reviews',
                'nav.google_reviews',
                googleProfiles(),
                Star,
            ),
        can.manageCompany &&
            item('messaging', 'nav.messaging', messaging(), MessageSquare),
        can.manageCompany &&
            item(
                'company-settings',
                'nav.company_settings',
                companySettings(),
                Building,
            ),
        can.viewTrash && item('trash', 'nav.deleted_items', trash(), Trash2),
        can.viewChangeLog &&
            item('change-log', 'nav.change_log', changeLog(), History),
        can.viewChangeLog &&
            item('booking-bot', 'nav.booking_bot', bookingBot(), Bot),
    ].filter(Boolean) as AppNavItem[];

    const platform = auth.user?.is_super_admin
        ? [item('companies', 'nav.companies', adminCompanies(), Building2)]
        : [];

    const groups = (
        [
            {
                key: 'main',
                label: 'nav.group_main',
                items: auth.company ? main : [],
            },
            {
                key: 'money',
                label: 'nav.group_money',
                items: auth.company ? money : [],
            },
            {
                key: 'company',
                label: 'nav.group_company',
                items: auth.company ? company : [],
            },
            { key: 'platform', label: 'nav.group_platform', items: platform },
        ] satisfies AppNavGroup[]
    ).filter((group) => group.items.length > 0);

    return {
        bottom: auth.company ? bottom : [],
        groups,
        rootPaths: (auth.company ? bottom : []).map((b) => pathOf(b.href)),
        home: today ? today.href : dashboard(),
        inboxInHeader: smsAutomatic && Boolean(messages),
        messages: messages || null,
    };
}

/** The path of a link, for comparing with the current page. */
export function navPath(href: Href): string {
    return pathOf(href);
}
