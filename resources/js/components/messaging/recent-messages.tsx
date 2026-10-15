import { Link } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { useState } from 'react';
import { ChatThread } from '@/components/messaging/chat-thread';
import type { MessageItem } from '@/components/messaging/types';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';

/**
 * The last two messages with the customer and "Open chat" (UX-REDESIGN §26). People without the SMS Inbox can
 * still unfold the rest here.
 */
export function RecentMessages({
    messages,
    chatUrl = null,
    showJobLinks = false,
}: {
    /** Newest first. */
    messages: MessageItem[];
    chatUrl?: string | null;
    showJobLinks?: boolean;
}) {
    const t = useTrans();
    const [all, setAll] = useState(false);
    const shown = all ? messages : messages.slice(0, 2);

    return (
        <div className="space-y-3" data-test="recent-messages">
            {messages.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('messages.empty')}
                </p>
            ) : (
                <ChatThread messages={shown} showJobLinks={showJobLinks} />
            )}
            <div className="flex flex-wrap gap-2">
                {chatUrl && (
                    <Button variant="outline" className="h-11" asChild>
                        <Link href={chatUrl} data-test="open-chat">
                            <MessageSquare /> {t('messages.chat.open')}
                        </Link>
                    </Button>
                )}
                {!all && messages.length > 2 && (
                    <Button
                        variant="ghost"
                        className="h-11"
                        onClick={() => setAll(true)}
                    >
                        {t('messages.chat.show_all', {
                            count: messages.length,
                        })}
                    </Button>
                )}
            </div>
        </div>
    );
}
