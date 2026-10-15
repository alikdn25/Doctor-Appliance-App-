import {
    Head,
    Link,
    router,
    useForm,
    usePage,
    usePoll,
} from '@inertiajs/react';
import type { CountryCode } from 'libphonenumber-js/min';
import {
    isSupportedCountry,
    parsePhoneNumberFromString,
} from 'libphonenumber-js/min';
import {
    ArrowLeft,
    Briefcase,
    MessageSquare,
    Phone,
    Plus,
    Send,
    Smartphone,
    UserPlus,
    UserRound,
    Zap,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { ChatThread } from '@/components/messaging/chat-thread';
import type { MessageItem } from '@/components/messaging/types';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterChips } from '@/components/ui/filter-chips';
import { IconButton } from '@/components/ui/icon-button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchField } from '@/components/ui/search-field';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';
import { cn } from '@/lib/utils';
import { edit as messagingSettings } from '@/routes/company/messaging';
import {
    create as customerCreate,
    show as customerShow,
} from '@/routes/customers';
import { show as showJob } from '@/routes/jobs';
import { index, older, read, send } from '@/routes/messages';

type SmsMode = 'automatic' | 'technician_phone' | 'off';
type Thread = {
    phone: string;
    customer_name: string | null;
    preview: string;
    unread_count: number;
    latest: MessageItem;
};
type Template = { key: string; label: string; text: string };
type Conversation = {
    phone: string;
    sms_mode: SmsMode;
    customer: { id: number; display_name: string } | null;
    job: { id: number; number: string } | null;
    templates: Template[];
    blocked: string | null;
    messages: Paginated<MessageItem> & { total: number };
    unread_ids: number[];
};
type Filters = { search: string; unread: boolean };

/**
 * The reply box pinned under the chat, with ready texts next to it (UX-REDESIGN §26). In "From technician's
 * phone" mode the text opens in the phone's messages app instead.
 */
function Composer({ conversation }: { conversation: Conversation }) {
    const t = useTrans();
    const form = useForm({ phone: conversation.phone, body: '' });
    // Without Automatic SMS the text still goes out: from the employee's own phone.
    const fromPhone = conversation.sms_mode !== 'automatic';
    const blocked = fromPhone ? null : conversation.blocked;
    const body = form.data.body.trim();
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (fromPhone || body === '') {
            return;
        }

        form.post(send().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="da-pinned da-action-bar sticky bottom-0 z-30 -mx-4 space-y-2 border-t p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:-mx-6 md:mx-0 md:rounded-2xl md:border"
        >
            {(blocked || fromPhone) && (
                <p role="status" className="text-xs text-muted-foreground">
                    {blocked ??
                        (conversation.sms_mode === 'technician_phone'
                            ? t('messages.chat.replies_to_phone')
                            : t('messages.inbox.phone_mode_hint'))}
                </p>
            )}
            <div className="flex items-end gap-2">
                {conversation.templates.length > 0 && blocked === null && (
                    <ActionSheet
                        title={t('messages.chat.templates')}
                        description={t('messages.chat.template_hint')}
                        trigger={(open) => (
                            <IconButton
                                label={t('messages.chat.templates')}
                                onClick={open}
                                data-test="chat-templates"
                            >
                                <Zap />
                            </IconButton>
                        )}
                        items={conversation.templates.map((template) => ({
                            key: template.key,
                            label: template.label,
                            onSelect: () => form.setData('body', template.text),
                        }))}
                    />
                )}
                <Label htmlFor="sms-reply" className="sr-only">
                    {t('messages.chat.message')}
                </Label>
                <Textarea
                    id="sms-reply"
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    maxLength={1600}
                    rows={1}
                    disabled={blocked !== null}
                    placeholder={t('messages.inbox.reply_placeholder')}
                    className="[field-sizing:content] max-h-40 min-h-11 flex-1 resize-none"
                />
                {fromPhone ? (
                    <IconButton
                        label={t('messages.inbox.from_my_phone')}
                        variant="primary"
                        asChild={body !== ''}
                        disabled={body === ''}
                    >
                        {body !== '' ? (
                            <a
                                href={smsUrl(conversation.phone, body)}
                                onClick={() => form.reset('body')}
                            >
                                <Smartphone />
                            </a>
                        ) : (
                            <Smartphone />
                        )}
                    </IconButton>
                ) : (
                    <IconButton
                        type="submit"
                        label={t('messages.send_sms')}
                        variant="primary"
                        disabled={
                            form.processing || blocked !== null || body === ''
                        }
                    >
                        <Send />
                    </IconButton>
                )}
            </div>
            <InputError message={form.errors.body ?? form.errors.phone} />
        </form>
    );
}

/**
 * The chat screen: newest at the bottom, scrolled there on open and on each new message; earlier messages load
 * when the top is reached.
 */
function Chat({
    conversation,
    backUrl,
}: {
    conversation: Conversation;
    backUrl: string;
}) {
    const t = useTrans();
    const phoneText = usePhone();
    const [earlier, setEarlier] = useState<MessageItem[]>([]);
    const [hasMore, setHasMore] = useState(
        conversation.messages.total > conversation.messages.data.length,
    );
    const [loading, setLoading] = useState(false);
    const end = useRef<HTMLDivElement>(null);
    const top = useRef<HTMLDivElement>(null);
    const newestId = conversation.messages.data[0]?.id;
    const messages = [...conversation.messages.data, ...earlier];
    const oldestId = messages[messages.length - 1]?.id;

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [newestId]);

    const loadEarlier = async () => {
        if (loading || !hasMore || !oldestId) {
            return;
        }

        setLoading(true);
        const before = document.documentElement.scrollHeight;

        try {
            const response = await fetch(
                older({
                    query: { phone: conversation.phone, before: oldestId },
                }).url,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            const data = (await response.json()) as {
                messages: MessageItem[];
            };
            setEarlier((list) => [...list, ...data.messages]);
            setHasMore(data.messages.length === 50);
            // Keep the reader where they were: the new messages go above.
            requestAnimationFrame(() =>
                window.scrollBy(
                    0,
                    document.documentElement.scrollHeight - before,
                ),
            );
        } finally {
            setLoading(false);
        }
    };

    // Earlier messages load by themselves when the top comes into view.
    useLayoutEffect(() => {
        const node = top.current;

        if (!node || !hasMore) {
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                void loadEarlier();
            }
        });
        observer.observe(node);

        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [hasMore, oldestId]);

    const name =
        conversation.customer?.display_name ?? t('messages.inbox.unknown');

    return (
        <>
            <ScreenHeader
                title={name}
                subtitle={phoneText(conversation.phone)}
                // Always back to the list: after sending, the browser history holds the chat itself.
                leading={
                    <Link
                        href={backUrl}
                        className={headerButtonClass}
                        aria-label={t('nav.back')}
                        title={t('nav.back')}
                        data-test="chat-back"
                    >
                        <ArrowLeft className="size-5" />
                    </Link>
                }
                actions={
                    <>
                        <a
                            href={`tel:${conversation.phone}`}
                            className={headerButtonClass}
                            aria-label={t('messages.inbox.call')}
                            title={t('messages.inbox.call')}
                        >
                            <Phone className="size-5" />
                        </a>
                        {conversation.job && (
                            <Link
                                href={showJob(conversation.job.id)}
                                className={headerButtonClass}
                                aria-label={t('messages.open_job')}
                                title={`#${conversation.job.number}`}
                                data-test="chat-job"
                            >
                                <Briefcase className="size-5" />
                            </Link>
                        )}
                        <ActionSheet
                            title={name}
                            items={[
                                conversation.customer
                                    ? {
                                          key: 'customer',
                                          label: t(
                                              'messages.inbox.open_customer',
                                          ),
                                          icon: <UserRound />,
                                          onSelect: () =>
                                              router.visit(
                                                  customerShow(
                                                      conversation.customer!.id,
                                                  ),
                                              ),
                                      }
                                    : {
                                          key: 'add',
                                          label: t('customers.add'),
                                          icon: <UserPlus />,
                                          onSelect: () =>
                                              router.visit(customerCreate()),
                                      },
                            ]}
                        />
                    </>
                }
            />

            <section
                aria-label={t('messages.inbox.conversation')}
                className="mx-auto flex min-h-[calc(100dvh-9rem)] w-full max-w-3xl flex-col px-4 pt-3 sm:px-6"
            >
                <div ref={top} />
                {hasMore && (
                    <div className="flex justify-center pb-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => void loadEarlier()}
                            disabled={loading}
                        >
                            {t('messages.chat.load_older')}
                        </Button>
                    </div>
                )}
                <div className="flex-1">
                    {messages.length === 0 ? (
                        <p className="py-10 text-center text-sm text-muted-foreground">
                            {t('messages.inbox.start')}
                        </p>
                    ) : (
                        <ChatThread messages={messages} showJobLinks />
                    )}
                </div>
                <div className="h-3" ref={end} />
                <Composer
                    key={conversation.phone}
                    conversation={conversation}
                />
            </section>
        </>
    );
}

/** Opens a conversation with any number, so a lead can be texted or called before they are booked. */
function NewMessage({ onDone }: { onDone: () => void }) {
    const t = useTrans();
    const { auth } = usePage().props;
    const country = auth.company?.country ?? 'US';
    const [number, setNumber] = useState('');
    const [error, setError] = useState<string>();
    const open = (event: FormEvent) => {
        event.preventDefault();
        const parsed = parsePhoneNumberFromString(
            number,
            isSupportedCountry(country) ? (country as CountryCode) : undefined,
        );

        if (!parsed?.isPossible()) {
            setError(t('messages.inbox.invalid_number'));

            return;
        }

        onDone();
        router.get(index({ query: { phone: parsed.number } }).url);
    };

    return (
        <form onSubmit={open} className="da-card space-y-2 p-4">
            <h2 className="font-semibold">{t('messages.inbox.new_title')}</h2>
            <p className="text-sm text-muted-foreground">
                {t('messages.inbox.new_hint')}
            </p>
            <Label htmlFor="new-number">{t('messages.inbox.number')}</Label>
            <div className="flex gap-2">
                <Input
                    id="new-number"
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    autoFocus
                    value={number}
                    maxLength={30}
                    onChange={(event) => {
                        setNumber(event.target.value);
                        setError(undefined);
                    }}
                />
                <Button type="submit" className="min-h-11">
                    {t('messages.inbox.open')}
                </Button>
            </div>
            <InputError message={error} />
        </form>
    );
}

/**
 * SMS Inbox (UX-REDESIGN §10.5, §26): the list of conversations (live search, Unread only chip, an empty state that
 * depends on the SMS mode); a conversation opens as its own chat screen with ←.
 */
export default function SmsInbox({
    threads,
    conversation,
    filters,
    smsMode,
    canSetUpMessaging = false,
}: {
    threads: Paginated<Thread>;
    conversation: Conversation | null;
    filters: Filters;
    smsMode: SmsMode;
    canSetUpMessaging?: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const phoneText = usePhone();
    const [composing, setComposing] = useState(false);
    const lastRead = useRef('');
    usePoll(15000, { only: ['threads', 'conversation', 'unreadMessages'] });
    const phone = conversation?.phone;
    const unreadIds = conversation?.unread_ids.join(',') ?? '';
    const listQuery = {
        ...(filters.search ? { search: filters.search } : {}),
        ...(filters.unread ? { unread: 1 } : {}),
    };

    useEffect(() => {
        const key = `${phone}:${unreadIds}`;

        if (!phone || unreadIds === '' || key === lastRead.current) {
            return;
        }

        lastRead.current = key;
        router.post(
            read().url,
            { phone, message_ids: unreadIds.split(',').map(Number) },
            { preserveScroll: true, preserveState: true },
        );
    }, [phone, unreadIds]);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            index().url,
            {
                ...(merged.search ? { search: merged.search } : {}),
                ...(merged.unread ? { unread: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    if (conversation) {
        return (
            <>
                <Head title={t('messages.inbox.title')} />
                <Chat
                    conversation={conversation}
                    backUrl={index({ query: listQuery }).url}
                />
            </>
        );
    }

    const filtered = filters.search !== '' || filters.unread;

    return (
        <>
            <Head title={t('messages.inbox.title')} />

            <ScreenHeader
                title={t('messages.inbox.title')}
                actions={
                    <button
                        type="button"
                        className={headerButtonClass}
                        aria-label={t('messages.inbox.new')}
                        title={t('messages.inbox.new')}
                        aria-expanded={composing}
                        onClick={() => setComposing(!composing)}
                    >
                        <Plus className="size-5" />
                    </button>
                }
            />

            <section
                aria-label={t('messages.inbox.conversations')}
                className="mx-auto w-full max-w-3xl space-y-3 p-4 sm:p-6"
            >
                {composing && <NewMessage onDone={() => setComposing(false)} />}

                <SearchField
                    value={filters.search}
                    placeholder={t('messages.inbox.search')}
                    onSearch={(search) => apply({ search })}
                />
                <FilterChips
                    label={t('messages.inbox.unread_only')}
                    selected={filters.unread ? ['unread'] : []}
                    onChange={() => apply({ unread: !filters.unread })}
                    chips={[
                        {
                            value: 'unread',
                            label: t('messages.inbox.unread_only'),
                        },
                    ]}
                />

                {threads.data.length === 0 ? (
                    <div className="da-card">
                        <EmptyState
                            icon={MessageSquare}
                            title={
                                filtered
                                    ? t('messages.inbox.empty')
                                    : t(`messages.inbox.empty_${smsMode}`)
                            }
                            action={
                                !filtered &&
                                smsMode !== 'automatic' &&
                                canSetUpMessaging ? (
                                    <Button variant="outline" asChild>
                                        <Link href={messagingSettings()}>
                                            {t('messages.inbox.settings_link')}
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    </div>
                ) : (
                    <ul
                        className="da-card divide-y overflow-hidden"
                        data-test="thread-list"
                    >
                        {threads.data.map((thread) => (
                            <li key={thread.phone}>
                                <Link
                                    href={index({
                                        query: {
                                            phone: thread.phone,
                                            ...listQuery,
                                        },
                                    })}
                                    className="flex min-h-16 min-w-0 items-center gap-3 px-3 py-2.5 hover:bg-black/[0.03]"
                                >
                                    <span
                                        className={cn(
                                            'da-soft flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                                        )}
                                        aria-hidden="true"
                                    >
                                        {(thread.customer_name ?? '#')
                                            .slice(0, 1)
                                            .toUpperCase()}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span
                                                className={cn(
                                                    'truncate',
                                                    thread.unread_count > 0
                                                        ? 'font-bold'
                                                        : 'font-semibold',
                                                )}
                                            >
                                                {thread.customer_name ??
                                                    phoneText(thread.phone)}
                                            </span>
                                            {thread.latest.at && (
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {time.isToday(
                                                        thread.latest.at,
                                                    )
                                                        ? time.time(
                                                              thread.latest.at,
                                                          )
                                                        : time.day(
                                                              thread.latest.at,
                                                          )}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <p className="min-w-0 flex-1 truncate text-sm text-muted-foreground">
                                                {thread.latest.direction ===
                                                    'outbound' &&
                                                    `${t('messages.chat.you')}: `}
                                                {thread.preview}
                                            </p>
                                            {thread.unread_count > 0 && (
                                                <span
                                                    className="da-primary inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-xs font-bold"
                                                    aria-label={t(
                                                        'messages.inbox.unread_count',
                                                        {
                                                            count: thread.unread_count,
                                                        },
                                                    )}
                                                >
                                                    {thread.unread_count}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <PaginationLinks links={threads.links} />
            </section>
        </>
    );
}

SmsInbox.layout = {
    breadcrumbs: [{ title: 'messages.inbox.title', href: index() }],
};
