import { AlertTriangle } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { statusLook, statusStyle } from '@/lib/status';
import { cn } from '@/lib/utils';

/**
 * A status is always colour + icon + text, never colour alone (UX-REDESIGN §4.3). The same look is used for
 * jobs, visits, estimates and invoices.
 */
export function StatusBadge({
    status,
    label,
    className,
}: {
    status: string;
    label: string;
    className?: string;
}) {
    const { icon: Icon, strike } = statusLook(status);

    return (
        <span
            data-status={status}
            style={statusStyle(status)}
            className={cn(
                'da-status inline-flex h-6 w-fit shrink-0 items-center gap-1 rounded-full pr-2.5 pl-2 text-xs font-medium whitespace-nowrap',
                strike && 'line-through',
                className,
            )}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            {label}
        </span>
    );
}

/**
 * Overdue is not a status but a red flag shown next to any status (a visit past its window, an invoice past due).
 */
export function OverdueFlag({
    label,
    className,
}: {
    /** Defaults to "Overdue"; e.g. "Overdue 3 days". */
    label?: string;
    className?: string;
}) {
    const t = useTrans();

    return (
        <span
            data-test="overdue-flag"
            className={cn(
                'da-overdue inline-flex h-6 w-fit shrink-0 items-center gap-1 rounded-full pr-2.5 pl-2 text-xs font-semibold whitespace-nowrap',
                className,
            )}
        >
            <AlertTriangle className="size-3.5" aria-hidden="true" />
            {label ?? t('ui.overdue')}
        </span>
    );
}

/** "Draft" on a booking that was saved without finishing (no confirmation sent yet): orange, next to its status. */
export function DraftChip({ className }: { className?: string }) {
    const t = useTrans();

    return (
        <span
            data-test="draft-chip"
            className={cn(
                'da-draft-chip inline-flex h-5 w-fit shrink-0 items-center rounded-full px-2 text-[11px] font-semibold whitespace-nowrap',
                className,
            )}
        >
            {t('jobs.statuses.booking_draft')}
        </span>
    );
}
