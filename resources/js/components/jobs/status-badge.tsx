import { cn } from '@/lib/utils';

const tones: Record<string, string> = {
    new: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    scheduled:
        'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    on_the_way:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    in_progress:
        'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    waiting_for_parts:
        'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-200',
    completed:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    invoiced: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    paid: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    cancelled:
        'bg-zinc-200 text-zinc-700 line-through dark:bg-zinc-800 dark:text-zinc-300',
    on_hold: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
};

export function StatusBadge({
    status,
    label,
    className,
}: {
    status: string;
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex w-fit shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                tones[status] ?? 'bg-muted text-muted-foreground',
                className,
            )}
        >
            {label}
        </span>
    );
}
