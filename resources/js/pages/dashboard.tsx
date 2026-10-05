import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    Contact,
    Percent,
    Tags,
    Users,
    Wrench,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useTrans } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as brands } from '@/routes/brands';
import { index as team } from '@/routes/team';
import { create as createCustomer } from '@/routes/customers';
import { create as createJob, mine as myJobs } from '@/routes/jobs';
import { index as taxes } from '@/routes/taxes';
import { edit as googleProfiles } from '@/routes/company/google-profiles';

type Props = {
    companyName: string;
    stats: { brands: number; members: number };
    setup?: { taxes: boolean; reviewProfiles: boolean; reviewsOn: boolean };
};

export default function Dashboard({
    companyName,
    stats,
    setup = { taxes: true, reviewProfiles: true, reviewsOn: false },
}: Props) {
    const { auth } = usePage().props;
    const t = useTrans();

    const tiles = [
        {
            label: t('dashboard.active_brands'),
            value: stats.brands,
            icon: Tags,
            href: auth.can.viewBrands ? brands() : null,
        },
        {
            label: t('dashboard.active_members'),
            value: stats.members,
            icon: Users,
            href: auth.can.manageTeam ? team() : null,
        },
    ];

    const missing = [
        !setup.taxes && {
            text: t('dashboard.missing_taxes'),
            action: t('dashboard.configure_taxes'),
            href: auth.can.viewTaxes ? taxes() : null,
        },
        setup.reviewsOn &&
            !setup.reviewProfiles && {
                text: t('dashboard.missing_review_profile'),
                action: t('dashboard.add_review_profile'),
                href: auth.can.manageChecklists ? googleProfiles() : null,
            },
    ].filter((item) => item !== false);

    const actions = [
        auth.can.viewCustomers === true && {
            title: t('dashboard.add_customer'),
            description: t('dashboard.add_customer_hint'),
            href: createCustomer(),
            icon: Contact,
        },
        auth.can.viewJobs === true && {
            title: t('dashboard.schedule_job'),
            description: t('dashboard.schedule_job_hint'),
            href: createJob({ query: { book: 1 } }),
            icon: CalendarDays,
        },
        auth.can.viewMyJobs === true && {
            title: t('dashboard.my_work'),
            description: t('dashboard.my_work_hint'),
            href: myJobs(),
            icon: Wrench,
        },
        auth.can.manageTeam === true && {
            title: t('dashboard.invite_team'),
            description: t('dashboard.invite_team_hint'),
            href: team(),
            icon: Users,
        },
        auth.can.viewTaxes === true && {
            title: t('dashboard.configure_taxes'),
            description: t('dashboard.configure_taxes_hint'),
            href: taxes(),
            icon: Percent,
        },
    ].filter((action) => action !== false);

    return (
        <>
            <Head title={t('nav.getting_started')} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={t('dashboard.welcome', { name: auth.user.name })}
                    description={t('dashboard.subtitle', {
                        company: companyName,
                    })}
                />

                {stats.brands === 0 && (
                    <div className="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        <h2 className="font-semibold">
                            {t('dashboard.brand_needed')}
                        </h2>
                        <p className="mt-2 text-sm">
                            {t(
                                auth.can.manageCompany
                                    ? 'dashboard.brand_needed_hint'
                                    : 'dashboard.brand_needed_admin',
                            )}
                        </p>
                        {auth.can.manageCompany && (
                            <Button asChild variant="outline" className="mt-4">
                                <Link href={brands()}>
                                    {t('dashboard.brand_needed')}
                                </Link>
                            </Button>
                        )}
                    </div>
                )}

                {missing.length > 0 && (
                    <section
                        aria-labelledby="setup-missing"
                        className="space-y-2 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950"
                    >
                        <h2 id="setup-missing" className="font-semibold">
                            {t('dashboard.setup_missing')}
                        </h2>
                        <ul className="space-y-2">
                            {missing.map((item) => (
                                <li
                                    key={item.text}
                                    className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                >
                                    <span>{item.text}</span>
                                    {item.href && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link href={item.href}>
                                                {item.action}
                                            </Link>
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="grid grid-cols-2 gap-3 md:max-w-xl">
                    {tiles.map((tile) => {
                        const body = (
                            <Card className="py-4">
                                <CardContent className="flex items-center gap-3 px-4">
                                    <tile.icon className="size-5 text-muted-foreground" />
                                    <div>
                                        <div className="text-2xl font-semibold">
                                            {tile.value}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {tile.label}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );

                        return tile.href ? (
                            <Link key={tile.label} href={tile.href}>
                                {body}
                            </Link>
                        ) : (
                            <div key={tile.label}>{body}</div>
                        );
                    })}
                </div>

                <section aria-labelledby="next-steps" className="space-y-4">
                    <div>
                        <h2 id="next-steps" className="text-lg font-semibold">
                            {t('dashboard.next_steps')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                stats.brands === 0
                                    ? 'dashboard.brand_needed_hint'
                                    : 'dashboard.next_steps_hint',
                            )}
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {actions.map((action) => (
                            <Link
                                key={action.title}
                                href={action.href}
                                className="flex min-h-28 items-center gap-4 rounded-2xl border bg-card p-5 shadow-sm transition-colors hover:border-primary/40 hover:bg-primary/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            >
                                <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                    <action.icon
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <h3 className="font-semibold">
                                        {action.title}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {action.description}
                                    </p>
                                </div>
                                <ArrowRight
                                    className="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Link>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'nav.getting_started', href: dashboard() }],
};
