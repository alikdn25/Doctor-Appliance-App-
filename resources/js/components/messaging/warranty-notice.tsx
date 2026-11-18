import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import type { PossibleWarranty } from '@/components/messaging/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show as showJob } from '@/routes/jobs';

/**
 * "Possible warranty" (owner's task of November 18): the customer had a job finished within the company's window —
 * when, what was done, the parts, and a link to it. A hint only.
 */
export function WarrantyNotice({
    warranty,
}: {
    warranty: NonNullable<PossibleWarranty>;
}) {
    const t = useTrans();
    const time = useCompanyTime();

    return (
        <div
            className="mb-2 space-y-1 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
            data-test="possible-warranty"
        >
            <p className="flex items-center gap-1.5 font-semibold">
                <ShieldAlert className="size-4" aria-hidden />
                {t('automation.warranty.label')}
            </p>
            <p>
                {t('automation.warranty.body', {
                    number: warranty.number,
                    date: time.dateOnly(warranty.date),
                })}
            </p>
            {warranty.work && (
                <p>{t('automation.warranty.work', { work: warranty.work })}</p>
            )}
            {warranty.parts.length > 0 && (
                <p>
                    {t('automation.warranty.parts', {
                        parts: warranty.parts.join(', '),
                    })}
                </p>
            )}
            <Link
                href={showJob(warranty.job_id)}
                className="font-semibold underline"
            >
                {t('automation.warranty.open', { number: warranty.number })}
            </Link>
        </div>
    );
}
