import { Link } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { headerButtonClass } from '@/components/screen-header';
import { useAppNavigation } from '@/hooks/use-app-navigation';
import { useTrans } from '@/lib/i18n';

/**
 * With automatic texting the SMS inbox is a header icon on Today and Jobs, with the unread count
 * (UX-REDESIGN §5.1); otherwise it is in More.
 */
export function InboxHeaderButton() {
    const { inboxInHeader, messages } = useAppNavigation();
    const t = useTrans();

    if (!inboxInHeader || !messages) {
        return null;
    }

    return (
        <Link
            href={messages.href}
            aria-label={t('nav.sms_inbox')}
            className={`${headerButtonClass} relative`}
            data-test="inbox-header-button"
        >
            <MessageSquare className="size-5" aria-hidden="true" />
            {!!messages.badge && (
                <span className="absolute -top-1 -right-1 min-w-[18px] rounded-full bg-danger px-1 text-center text-[11px] leading-[18px] font-semibold text-white">
                    {messages.badge}
                </span>
            )}
        </Link>
    );
}
