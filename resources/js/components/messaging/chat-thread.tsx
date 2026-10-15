import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    CheckCheck,
    Clock,
    Smartphone,
} from 'lucide-react';
import type { MessageItem } from '@/components/messaging/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showJob } from '@/routes/jobs';

/**
 * A conversation drawn like a phone chat (UX-REDESIGN §26): the customer's texts on the left, ours on the right,
 * a date between days, oldest at the top and newest at the bottom. Under each of our texts its status: scheduled,
 * sent, delivered or failed with the reason.
 */
export function ChatThread({
    messages,
    showJobLinks = false,
}: {
    /** Newest first, as the server sends them. */
    messages: MessageItem[];
    showJobLinks?: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const ordered = [...messages].reverse();

    return (
        <ol className="space-y-1.5" data-test="chat-thread">
            {ordered.map((m, index) => {
                const inbound = m.direction === 'inbound';
                const day = m.at ? time.ymd(m.at) : null;
                const previousDay =
                    index > 0 && ordered[index - 1].at
                        ? time.ymd(ordered[index - 1].at!)
                        : null;

                return (
                    <li key={m.id} className="space-y-1.5">
                        {day && day !== previousDay && (
                            <div className="flex justify-center py-2">
                                <span className="da-badge rounded-full px-3 text-xs leading-6 font-medium text-muted-foreground">
                                    {time.isToday(m.at!)
                                        ? t('messages.chat.today')
                                        : time.fullDay(m.at!)}
                                </span>
                            </div>
                        )}
                        <div
                            className={cn(
                                'flex',
                                inbound ? 'justify-start' : 'justify-end',
                            )}
                        >
                            <div
                                className={cn(
                                    'max-w-[85%] min-w-0 space-y-1 sm:max-w-[70%]',
                                    !inbound && 'items-end text-right',
                                )}
                            >
                                <div
                                    data-direction={m.direction}
                                    className={cn(
                                        'rounded-2xl px-3.5 py-2 text-left text-[15px] leading-snug [overflow-wrap:anywhere] whitespace-pre-line shadow-[0_1px_2px_rgb(16_42_79/0.12)]',
                                        inbound
                                            ? 'rounded-bl-md border bg-card'
                                            : 'da-primary rounded-br-md',
                                        m.status === 'failed' &&
                                            !inbound &&
                                            'opacity-80',
                                    )}
                                >
                                    {m.body}
                                </div>
                                <div
                                    className={cn(
                                        'flex flex-wrap items-center gap-x-1.5 px-1 text-xs text-muted-foreground',
                                        !inbound && 'justify-end',
                                    )}
                                >
                                    {m.at && <span>{time.time(m.at)}</span>}
                                    {!inbound && m.user && (
                                        <span>· {m.user}</span>
                                    )}
                                    {!inbound && <MessageStatus message={m} />}
                                    {showJobLinks && m.job_id && (
                                        <Link
                                            href={showJob(m.job_id)}
                                            className="underline"
                                        >
                                            {t('messages.open_job')}
                                        </Link>
                                    )}
                                </div>
                                {!inbound &&
                                    m.status === 'failed' &&
                                    m.status_reason && (
                                        <p className="px-1 text-xs text-danger">
                                            {m.status_reason}
                                        </p>
                                    )}
                            </div>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

function MessageStatus({ message: m }: { message: MessageItem }) {
    if (m.channel === 'technician_phone') {
        return (
            <span className="inline-flex items-center gap-0.5">
                · <Smartphone className="size-3" aria-hidden="true" />
                {m.channel_label}
            </span>
        );
    }

    const Icon =
        m.status === 'failed' || m.status === 'blocked'
            ? AlertCircle
            : m.status === 'delivered'
              ? CheckCheck
              : m.status === 'sent'
                ? Check
                : Clock;

    return (
        <span
            data-test="message-status"
            className={cn(
                'inline-flex items-center gap-0.5',
                (m.status === 'failed' || m.status === 'blocked') &&
                    'font-medium text-danger',
            )}
        >
            · <Icon className="size-3" aria-hidden="true" />
            {m.status_label}
        </span>
    );
}
