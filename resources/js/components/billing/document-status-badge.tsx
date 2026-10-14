import { OverdueFlag, StatusBadge } from '@/components/ui/status-badge';
import { useTrans } from '@/lib/i18n';

/** Estimate and invoice status, with the red Overdue flag next to it when the invoice is past due. */
export function DocumentStatusBadge({
    status,
    label,
    className,
    overdue = false,
}: {
    status: string;
    label: string;
    className?: string;
    overdue?: boolean;
}) {
    const t = useTrans();

    return (
        <>
            <StatusBadge status={status} label={label} className={className} />
            {overdue && (
                <OverdueFlag
                    label={t('invoices.overdue')}
                    className={className}
                />
            )}
        </>
    );
}
