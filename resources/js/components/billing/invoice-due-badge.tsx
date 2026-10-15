import { DocumentStatusBadge } from '@/components/billing/document-status-badge';
import type { DocumentRow } from '@/components/billing/types';
import { OverdueFlag } from '@/components/ui/status-badge';
import { useTrans } from '@/lib/i18n';

/**
 * One badge that says where an invoice stands: "Paid", "Overdue 3 days", "Due in 5 days", "Due today".
 */
export function InvoiceDueBadge({ invoice }: { invoice: DocumentRow }) {
    const t = useTrans();
    const days = invoice.due_in_days;

    if (days === null || days === undefined) {
        return (
            <DocumentStatusBadge
                status={invoice.status}
                label={invoice.status_label}
            />
        );
    }

    if (days < 0) {
        return (
            <OverdueFlag label={t('invoices.overdue_days', { count: -days })} />
        );
    }

    return (
        <span
            data-test="due-badge"
            className="da-badge inline-flex h-6 w-fit shrink-0 items-center rounded-full px-2.5 text-xs font-medium whitespace-nowrap"
        >
            {days === 0
                ? t('invoices.due_today')
                : t('invoices.due_in_days', { count: days })}
        </span>
    );
}
