import { Head, Link, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { workspace } from '@/routes/admin/companies';
import { create } from '@/routes/onboarding/company';

export default function Workspaces({ companies }: { companies: { id: number; name: string }[] }) {
    const t = useTrans();
    return <div className="mx-auto w-full max-w-xl space-y-5 p-6">
        <Head title={t('nav.workspace')} />
        <h1 className="text-2xl font-semibold">{t('admin.workspace_title')}</h1>
        <p className="text-muted-foreground">{t('admin.workspace_hint')}</p>
        {companies.map((company) => <Button key={company.id} variant="outline" className="h-14 w-full justify-start" onClick={() => router.post(workspace(company.id).url)}><Building2 />{company.name}</Button>)}
        <Button asChild className="h-11"><Link href={create()}>{t('onboarding.title')}</Link></Button>
    </div>;
}
