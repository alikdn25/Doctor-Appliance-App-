import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const tones: Record<string, string> = {
    draft: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    revised:
        'bg-zinc-100 text-zinc-500 line-through dark:bg-zinc-800 dark:text-zinc-400',
    approved:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    declined: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
    invoiced: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    unpaid: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    partially_paid:
        'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    paid: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    partially_refunded:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    refunded:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    void: 'bg-zinc-200 text-zinc-700 line-through dark:bg-zinc-800 dark:text-zinc-300',
    overdue: 'bg-red-600 text-white dark:bg-red-700',
};

export function DocumentStatusBadge({
    status,
    label,
    className,
    overdue = false,
}: {
    status: string;
    label: string;
    className?: string;
    /** Adds a red "Overdue" badge after the status. */
    overdue?: boolean;
}) {
    const t = useTrans();

    return (
        <>
            <span
                className={cn(
                    'inline-flex w-fit shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                    tones[status] ?? 'bg-muted text-muted-foreground',
                    className,
                )}
            >
                {label}
            </span>
            {overdue && (
                <span
                    className={cn(
                        'inline-flex w-fit shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                        tones.overdue,
                        className,
                    )}
                >
                    {t('invoices.overdue')}
                </span>
            )}
        </>
    );
}
