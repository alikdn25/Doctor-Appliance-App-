import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarDays, Contact, Percent, Tags, Users, Wrench } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Card, CardContent } from '@/components/ui/card';
import { useTrans } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as brands } from '@/routes/brands';
import { index as team } from '@/routes/team';
import { create as createCustomer } from '@/routes/customers';
import { create as createJob, mine as myJobs } from '@/routes/jobs';
import { index as taxes } from '@/routes/taxes';

type Props = {
    companyName: string;
    stats: { brands: number; members: number };
};

export default function Dashboard({ companyName, stats }: Props) {
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

    const actions = [
        auth.can.viewCustomers === true && { title: t('dashboard.add_customer'), description: t('dashboard.add_customer_hint'), href: createCustomer(), icon: Contact },
        auth.can.viewJobs === true && { title: t('dashboard.schedule_job'), description: t('dashboard.schedule_job_hint'), href: createJob(), icon: CalendarDays },
        auth.can.viewMyJobs === true && { title: t('dashboard.my_work'), description: t('dashboard.my_work_hint'), href: myJobs(), icon: Wrench },
        auth.can.manageTeam === true && { title: t('dashboard.invite_team'), description: t('dashboard.invite_team_hint'), href: team(), icon: Users },
        auth.can.viewTaxes === true && { title: t('dashboard.configure_taxes'), description: t('dashboard.configure_taxes_hint'), href: taxes(), icon: Percent },
    ].filter((action) => action !== false);

    return (
        <>
            <Head title={t('nav.dashboard')} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={t('dashboard.welcome', { name: auth.user.name })}
                    description={t('dashboard.subtitle', {
                        company: companyName,
                    })}
                />

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
                        <h2 id="next-steps" className="text-lg font-semibold">{t('dashboard.next_steps')}</h2>
                        <p className="text-sm text-muted-foreground">{t('dashboard.next_steps_hint')}</p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {actions.map((action) => (
                            <Link key={action.title} href={action.href} className="flex min-h-28 items-center gap-4 rounded-2xl border bg-card p-5 shadow-sm transition-colors hover:border-primary/40 hover:bg-primary/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><action.icon className="size-5" aria-hidden="true" /></span>
                                <div className="min-w-0 flex-1"><h3 className="font-semibold">{action.title}</h3><p className="mt-1 text-sm text-muted-foreground">{action.description}</p></div>
                                <ArrowRight className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            </Link>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'nav.dashboard', href: dashboard() }],
};
