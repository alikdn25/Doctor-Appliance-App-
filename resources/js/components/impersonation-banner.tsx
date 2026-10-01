import { router, usePage } from '@inertiajs/react';
import { UserCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { stop } from '@/routes/impersonation';

export function ImpersonationBanner() {
    const { impersonation } = usePage().props;
    const t = useTrans();

    if (!impersonation) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-2 bg-amber-500 px-4 py-2 text-sm text-black"
        >
            <UserCheck className="size-4 shrink-0" />
            <span className="flex-1">
                {t('admin.impersonating', {
                    user: impersonation.userName,
                    company: impersonation.companyName,
                })}
            </span>
            <Button
                size="sm"
                variant="outline"
                className="border-black/30 bg-white/70 text-black hover:bg-white"
                onClick={() => router.delete(stop().url)}
            >
                {t('admin.stop_impersonating')}
            </Button>
        </div>
    );
}
