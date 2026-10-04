import { statusColor } from '@/lib/job-colors';
import { cn } from '@/lib/utils';

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
                statusColor(status).badge,
                className,
            )}
        >
            {label}
        </span>
    );
}
