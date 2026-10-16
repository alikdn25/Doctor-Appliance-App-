import { Head, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building,
    CalendarClock,
    Car,
    CreditCard,
    Globe,
    MessageSquare,
    Percent,
    Receipt,
    ShieldCheck,
    Star,
    Tags,
    Users,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { ListRow } from '@/components/ui/list-row';
import { useTrans } from '@/lib/i18n';
import { index as brands } from '@/routes/brands';
import { edit as googleProfiles } from '@/routes/company/google-profiles';
import { edit as messaging } from '@/routes/company/messaging';
import { edit as services } from '@/routes/company/services';
import { edit, section as sectionRoute } from '@/routes/company/settings';
import { index as taxes } from '@/routes/taxes';
import { index as team } from '@/routes/team';
import type { Option } from '@/types';

type Day = { closed: boolean; open: string | null; close: string | null };

const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;

type HubCompany = {
    name: string;
    country: string;
    timezone: string;
    currency: string;
    locale: string;
    default_payment_terms: string;
    invoice_prefix: string | null;
    invoice_next_number: number;
    business_hours: Record<string, Day>;
    diagnostic_service_id: number | null;
    warranty_labor_value: number;
    warranty_labor_unit: string;
    warranty_parts_value: number;
    warranty_parts_unit: string;
    distance_unit: 'km' | 'mi';
    mileage_rate: string | null;
    payment_provider: string | null;
    accepts_cash: boolean;
};

type Hub = {
    taxes: number;
    services: number;
    brands: number;
    team: number;
    google_profiles: number;
    sms_mode: string;
} | null;

const tile =
    'da-soft inline-flex size-11 items-center justify-center rounded-[14px] [&_svg]:size-5';

/** "8–5" for "08:00"–"17:00" in the company's regional format. */
function hours(day: Day, locale: string): string {
    if (!day.open || !day.close) {
        return '';
    }

    const at = (time: string) => new Date(`2026-01-05T${time}:00Z`);

    return new Intl.DateTimeFormat(locale, {
        hour: 'numeric',
        minute:
            day.open.endsWith(':00') && day.close.endsWith(':00')
                ? undefined
                : '2-digit',
        timeZone: 'UTC',
    }).formatRange(at(day.open), at(day.close));
}

/**
 * Company settings as a list of short pages (UX-REDESIGN §12.1), each row with what is set now.
 */
export function SettingsHub({
    company,
    hub,
    countries,
    paymentTerms,
    services: serviceOptions,
    paymentProviders,
}: {
    company: HubCompany;
    hub: Hub;
    countries: Option[];
    paymentTerms: Option[];
    services: Option[];
    paymentProviders: Option[];
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const locale = auth.company?.locale ?? 'en-US';
    const unit = (value: number, kind: string) =>
        t(`company.hub.length.${kind}`, { count: value });

    // "Mon–Fri 8–5": the days open with the same hours, in order.
    const openDays = WEEKDAYS.filter(
        (day) => !company.business_hours[day]?.closed,
    );
    const first = openDays[0];
    const sameHours = openDays.every(
        (day) =>
            hours(company.business_hours[day], locale) ===
            hours(company.business_hours[first], locale),
    );
    const dayName = (day: string) => t(`company.weekdays.${day}`);
    const scheduling =
        openDays.length === 0
            ? t('company.hub.closed')
            : sameHours
              ? `${openDays.length > 1 ? `${dayName(first)}–${dayName(openDays[openDays.length - 1])}` : dayName(first)} ${hours(company.business_hours[first], locale)}`
              : t('company.hub.custom_hours');

    const rows: {
        key: string;
        href: ReturnType<typeof edit>;
        icon: ReactNode;
        title: string;
        subtitle: string;
    }[] = [
        {
            key: 'region',
            href: sectionRoute('region'),
            icon: <Globe />,
            title: t('company.sections.region'),
            subtitle: [
                countries.find((c) => c.value === company.country)?.label ??
                    company.country,
                company.timezone.split('/').pop()?.replace(/_/g, ' '),
                company.currency,
            ].join(' · '),
        },
        {
            key: 'invoices',
            href: sectionRoute('invoices'),
            icon: <Receipt />,
            title: t('company.sections.invoices'),
            subtitle: [
                paymentTerms.find(
                    (o) => o.value === company.default_payment_terms,
                )?.label,
                t('company.hub.next_invoice', {
                    number: `${company.invoice_prefix ?? ''}${company.invoice_next_number}`,
                }),
            ]
                .filter(Boolean)
                .join(' · '),
        },
        {
            key: 'scheduling',
            href: sectionRoute('scheduling'),
            icon: <CalendarClock />,
            title: t('company.sections.scheduling'),
            subtitle: scheduling,
        },
        {
            key: 'jobs',
            href: sectionRoute('jobs'),
            icon: <Wrench />,
            title: t('company.sections.jobs'),
            subtitle: t('company.hub.diagnostic_fee', {
                service:
                    serviceOptions.find(
                        (o) =>
                            o.value === String(company.diagnostic_service_id),
                    )?.label ?? t('company.hub.none'),
            }),
        },
        {
            key: 'warranty',
            href: sectionRoute('warranty'),
            icon: <ShieldCheck />,
            title: t('company.sections.warranty'),
            subtitle: `${unit(company.warranty_labor_value, company.warranty_labor_unit)} / ${unit(company.warranty_parts_value, company.warranty_parts_unit)}`,
        },
        {
            key: 'mileage',
            href: sectionRoute('mileage'),
            icon: <Car />,
            title: t('company.sections.mileage'),
            subtitle: `${t(`trips.units.${company.distance_unit}`)} · ${
                company.mileage_rate
                    ? t('company.hub.rate', {
                          rate: company.mileage_rate,
                          currency: company.currency,
                      })
                    : t('company.hub.rate_not_set')
            }`,
        },
        {
            key: 'payments',
            href: sectionRoute('payments'),
            icon: <CreditCard />,
            title: t('company.sections.payments'),
            subtitle: [
                paymentProviders.find(
                    (o) => o.value === company.payment_provider,
                )?.label ?? t('company.hub.manual_only'),
                company.accepts_cash ? t('company.hub.cash') : null,
            ]
                .filter(Boolean)
                .join(' · '),
        },
    ];

    const others: typeof rows = hub
        ? [
              {
                  key: 'taxes',
                  href: taxes(),
                  icon: <Percent />,
                  title: t('nav.taxes'),
                  subtitle: t('company.hub.taxes', { count: hub.taxes }),
              },
              {
                  key: 'services',
                  href: services(),
                  icon: <BookOpen />,
                  title: t('nav.services'),
                  subtitle: t('company.hub.services', { count: hub.services }),
              },
              {
                  key: 'messaging',
                  href: messaging(),
                  icon: <MessageSquare />,
                  title: t('nav.messaging'),
                  subtitle: hub.sms_mode,
              },
              {
                  key: 'google',
                  href: googleProfiles(),
                  icon: <Star />,
                  title: t('nav.google_reviews'),
                  subtitle: t('company.hub.locations', {
                      count: hub.google_profiles,
                  }),
              },
              {
                  key: 'brands',
                  href: brands(),
                  icon: <Tags />,
                  title: t('nav.brands'),
                  subtitle: t('company.hub.brands', { count: hub.brands }),
              },
              {
                  key: 'team',
                  href: team(),
                  icon: <Users />,
                  title: t('nav.team'),
                  subtitle: t('company.hub.people', { count: hub.team }),
              },
          ]
        : [];

    return (
        <>
            <Head title={t('company.title')} />
            <ScreenHeader title={t('company.title')} subtitle={company.name} />

            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 sm:p-6">
                {[rows, others].map((list, index) =>
                    list.length === 0 ? null : (
                        <div
                            key={index}
                            className="da-card divide-y overflow-hidden"
                            data-test={
                                index === 0 ? 'settings-hub' : 'settings-more'
                            }
                        >
                            {list.map((row) => (
                                <ListRow
                                    key={row.key}
                                    href={row.href}
                                    leading={
                                        <span className={tile}>{row.icon}</span>
                                    }
                                    title={row.title}
                                    subtitle={row.subtitle}
                                    chevron
                                />
                            ))}
                        </div>
                    ),
                )}
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Building className="size-4" aria-hidden="true" />
                    {t('company.description')}
                </p>
            </div>
        </>
    );
}
