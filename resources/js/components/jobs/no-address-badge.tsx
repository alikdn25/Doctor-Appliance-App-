import { MapPinOff } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** The orange "No address" tag of a job booked without an address (My Jobs, calendar, job page). */
export function NoAddressBadge({ className }: { className?: string }) {
    const t = useTrans();

    return (
        <span
            data-test="no-address"
            className={cn(
                'da-no-address inline-flex h-6 shrink-0 items-center gap-1 rounded-full px-2 text-xs font-semibold whitespace-nowrap',
                className,
            )}
        >
            <MapPinOff className="size-3.5" aria-hidden="true" />
            {t('jobs.backlog.reasons.no_address')}
        </span>
    );
}
