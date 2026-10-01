import { Head, Link, usePage } from '@inertiajs/react';
import { Tags, Users } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Card, CardContent } from '@/components/ui/card';
import { useTrans } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as brands } from '@/routes/brands';
import { index as team } from '@/routes/team';

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

    return (
        <>
            <Head title={t('nav.dashboard')} />

            <div className="flex flex-1 flex-col gap-4 p-4">
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

                <p className="text-sm text-muted-foreground">
                    {t('dashboard.coming_soon')}
                </p>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'nav.dashboard', href: dashboard() }],
};
