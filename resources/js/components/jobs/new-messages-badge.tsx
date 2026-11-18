import { MessageSquare } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * New texts from the customer of an unfinished job (owner's task of November 18), with the count; gone once the chat
 * is read. Shown on job cards in My Jobs and the calendar.
 */
export function NewMessagesBadge({
    count,
    className,
}: {
    count: number | undefined;
    className?: string;
}) {
    const t = useTrans();

    if (!count) {
        return null;
    }

    return (
        <span
            data-test="new-messages"
            aria-label={t('automation.inbox.new_messages', { count })}
            title={t('automation.inbox.new_messages', { count })}
            className={cn(
                'inline-flex h-6 shrink-0 items-center gap-1 rounded-full bg-red-600 px-2 text-xs font-bold text-white',
                className,
            )}
        >
            <MessageSquare className="size-3.5" aria-hidden="true" />
            {count}
        </span>
    );
}
