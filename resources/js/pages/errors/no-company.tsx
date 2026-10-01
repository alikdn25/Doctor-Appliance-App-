import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { logout } from '@/routes';

export default function NoCompany({
    hasSuspendedCompany,
}: {
    hasSuspendedCompany: boolean;
}) {
    const t = useTrans();

    return (
        <>
            <Head title={t('errors.no_company.title')} />

            <div className="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
                <Building2 className="size-10 text-muted-foreground" />
                <h1 className="text-xl font-semibold">
                    {t('errors.no_company.title')}
                </h1>
                <p className="max-w-md text-sm text-muted-foreground">
                    {hasSuspendedCompany
                        ? t('errors.no_company.suspended')
                        : t('errors.no_company.none')}
                </p>
                <Button variant="outline" asChild>
                    <Link href={logout()} as="button">
                        {t('nav.log_out')}
                    </Link>
                </Button>
            </div>
        </>
    );
}
