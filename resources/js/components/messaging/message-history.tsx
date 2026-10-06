import { Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Mail,
    MessageSquare,
    Smartphone,
} from 'lucide-react';
import type { MessageItem } from '@/components/messaging/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { show as showJob } from '@/routes/jobs';

/**
 * Texts and emails to and from the customer, read like a chat: oldest at the top, newest at the bottom.
 * Pages send the messages newest first (the latest ones are the ones loaded); they are shown in time order.
 */
export function MessageHistory({
    messages,
    showJobLinks = false,
}: {
    messages: MessageItem[];
    showJobLinks?: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const phoneText = usePhone();

    if (messages.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('messages.empty')}
            </p>
        );
    }

    return (
        <ul className="space-y-2">
            {[...messages].reverse().map((m) => {
                const Icon =
                    m.channel === 'email'
                        ? Mail
                        : m.channel === 'technician_phone'
                          ? Smartphone
                          : MessageSquare;
                const inbound = m.direction === 'inbound';

                return (
                    <li
                        key={m.id}
                        className={
                            inbound
                                ? 'mr-4 min-w-0 rounded-lg border bg-muted/50 p-3 text-sm [overflow-wrap:anywhere] sm:mr-8'
                                : 'ml-4 min-w-0 rounded-lg border p-3 text-sm [overflow-wrap:anywhere] sm:ml-8'
                        }
                    >
                        <div className="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            {inbound ? (
                                <ArrowDownLeft className="size-3" />
                            ) : (
                                <ArrowUpRight className="size-3" />
                            )}
                            <Icon className="size-3" />
                            <span>{m.kind_label}</span>
                            <span>·</span>
                            <span>{m.channel_label}</span>
                            {m.at && <span>· {time.dateTime(m.at)}</span>}
                            {m.user && <span>· {m.user}</span>}
                            {showJobLinks && m.job_id && (
                                <Link
                                    href={showJob(m.job_id)}
                                    className="underline"
                                >
                                    {t('messages.open_job')}
                                </Link>
                            )}
                        </div>
                        <p className="whitespace-pre-line">{m.body}</p>
                        <p
                            className={
                                ['failed', 'blocked'].includes(m.status)
                                    ? 'mt-1 text-xs text-destructive'
                                    : 'mt-1 text-xs text-muted-foreground'
                            }
                        >
                            {m.status_label}
                            {m.to &&
                                !inbound &&
                                ` · ${m.channel === 'email' ? m.to : phoneText(m.to)}`}
                            {m.status_reason && ` — ${m.status_reason}`}
                        </p>
                    </li>
                );
            })}
        </ul>
    );
}
