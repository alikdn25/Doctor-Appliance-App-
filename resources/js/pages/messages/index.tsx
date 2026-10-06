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
    MessageSquare,
    Phone,
    Plus,
    Send,
    Smartphone,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { MessageHistory } from '@/components/messaging/message-history';
import type { MessageItem } from '@/components/messaging/types';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';
import {
    create as customerCreate,
    show as customerShow,
} from '@/routes/customers';
import { index, read, send } from '@/routes/messages';

type Thread = {
    phone: string;
    customer_name: string | null;
    preview: string;
    unread_count: number;
    latest: MessageItem;
};
type Conversation = {
    phone: string;
    sms_mode: 'automatic' | 'technician_phone' | 'off';
    customer: { id: number; display_name: string } | null;
    blocked: string | null;
    messages: Paginated<MessageItem>;
    unread_ids: number[];
};
type Filters = { search: string; unread: boolean };

function Reply({ conversation }: { conversation: Conversation }) {
    const t = useTrans();
    const form = useForm({ phone: conversation.phone, body: '' });
    // Without Automatic SMS the text still goes out: from the employee's own phone.
    const fromPhone = conversation.sms_mode !== 'automatic';
    const blocked = fromPhone ? null : conversation.blocked;
    const body = form.data.body.trim();
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (fromPhone) return;
        form.post(send().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3 border-t p-4">
            <Label htmlFor="sms-reply">
                {conversation.messages.total === 0
                    ? t('messages.inbox.write')
                    : t('messages.inbox.reply')}
            </Label>
            {(blocked || fromPhone) && (
                <p role="status" className="text-sm text-muted-foreground">
                    {blocked ?? t('messages.inbox.phone_mode_hint')}
                </p>
            )}
            <Textarea
                id="sms-reply"
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                maxLength={1600}
                rows={3}
                disabled={blocked !== null}
                placeholder={t('messages.inbox.reply_placeholder')}
            />
            <InputError message={form.errors.body ?? form.errors.phone} />
            <div className="flex flex-wrap items-center justify-between gap-2">
                {fromPhone ? (
                    <Button
                        asChild={body !== ''}
                        className="ml-auto min-h-11"
                        disabled={body === ''}
                    >
                        {body !== '' ? (
                            <a
                                href={smsUrl(conversation.phone, body)}
                                onClick={() => form.reset('body')}
                            >
                                <Smartphone />{' '}
                                {t('messages.inbox.from_my_phone')}
                            </a>
                        ) : (
                            <>
                                <Smartphone />{' '}
                                {t('messages.inbox.from_my_phone')}
                            </>
                        )}
                    </Button>
                ) : (
                    <>
                        <p className="text-xs text-muted-foreground">
                            {t('messages.inbox.quiet_hint')}
                        </p>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={
                                form.processing ||
                                blocked !== null ||
                                body === ''
                            }
                        >
                            <Send /> {t('messages.send_sms')}
                        </Button>
                    </>
                )}
            </div>
        </form>
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
        <form
            onSubmit={open}
            className="mb-3 space-y-2 rounded-2xl border bg-card p-4 shadow-sm"
        >
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

export default function SmsInbox({
    threads,
    conversation,
    filters,
}: {
    threads: Paginated<Thread>;
    conversation: Conversation | null;
    filters: Filters;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const phoneText = usePhone();
    const [search, setSearch] = useState(filters.search);
    const [composing, setComposing] = useState(false);
    const lastRead = useRef('');
    usePoll(15000, { only: ['threads', 'conversation', 'unreadMessages'] });
    const phone = conversation?.phone;
    const unreadIds = conversation?.unread_ids.join(',') ?? '';
    const newestId = conversation?.messages.data[0]?.id;
    const conversationEnd = useRef<HTMLDivElement>(null);

    // Like any chat: the newest message sits at the bottom, next to the reply box. Opening a conversation and
    // every new message (sent, or received by the poll) bring the newest message and the reply box into view.
    useEffect(() => {
        if (phone) {
            conversationEnd.current?.scrollIntoView({ block: 'end' });
        }
    }, [phone, newestId]);

    useEffect(() => {
        const key = `${phone}:${unreadIds}`;
        if (!phone || unreadIds === '' || key === lastRead.current) return;
        lastRead.current = key;
        router.post(
            read().url,
            { phone, message_ids: unreadIds.split(',').map(Number) },
            { preserveScroll: true, preserveState: true },
        );
    }, [phone, unreadIds]);

    const apply = (unread = filters.unread) =>
        router.get(
            index().url,
            { search, unread: unread ? 1 : 0 },
            { preserveState: true, replace: true },
        );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        apply();
    };

    return (
        <>
            <Head title={t('messages.inbox.title')} />
            <div className="min-w-0 p-4">
                <PageHeader
                    title={t('messages.inbox.title')}
                    description={t('messages.inbox.description')}
                />
                <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(240px,320px)_minmax(0,1fr)]">
                    <section
                        aria-label={t('messages.inbox.conversations')}
                        className={
                            conversation ? 'hidden min-w-0 lg:block' : 'min-w-0'
                        }
                    >
                        <Button
                            className="mb-3 min-h-11 w-full"
                            aria-expanded={composing}
                            onClick={() => setComposing(!composing)}
                        >
                            <Plus /> {t('messages.inbox.new')}
                        </Button>
                        {composing && (
                            <NewMessage onDone={() => setComposing(false)} />
                        )}
                        <form onSubmit={submit} className="mb-3 flex gap-2">
                            <Input
                                type="search"
                                value={search}
                                maxLength={100}
                                aria-label={t('common.search')}
                                placeholder={t('messages.inbox.search')}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                className="min-h-11"
                            >
                                {t('common.search')}
                            </Button>
                        </form>
                        <Button
                            variant={filters.unread ? 'secondary' : 'outline'}
                            aria-pressed={filters.unread}
                            onClick={() => apply(!filters.unread)}
                            className="mb-3 min-h-11"
                        >
                            {t('messages.inbox.unread_only')}
                        </Button>
                        <ul className="divide-y overflow-hidden rounded-xl border">
                            {threads.data.map((thread) => (
                                <li key={thread.phone}>
                                    <Link
                                        href={index({
                                            query: {
                                                phone: thread.phone,
                                                search: filters.search,
                                                unread: filters.unread ? 1 : 0,
                                            },
                                        })}
                                        className={`block min-w-0 space-y-1 p-4 hover:bg-muted/50 ${thread.phone === phone ? 'bg-muted' : ''}`}
                                        aria-current={
                                            thread.phone === phone
                                                ? 'page'
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 font-medium break-words">
                                                {thread.customer_name ??
                                                    phoneText(thread.phone)}
                                            </span>
                                            {thread.unread_count > 0 && (
                                                <Badge
                                                    className="shrink-0"
                                                    aria-label={t(
                                                        'messages.inbox.unread_count',
                                                        {
                                                            count: thread.unread_count,
                                                        },
                                                    )}
                                                >
                                                    {thread.unread_count}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {phoneText(thread.phone)}
                                        </p>
                                        <p className="line-clamp-2 text-sm [overflow-wrap:anywhere]">
                                            {thread.preview}
                                        </p>
                                        {thread.latest.at && (
                                            <p className="text-xs text-muted-foreground">
                                                {time.dateTime(
                                                    thread.latest.at,
                                                )}{' '}
                                                · {thread.latest.status_label}
                                            </p>
                                        )}
                                    </Link>
                                </li>
                            ))}
                            {threads.data.length === 0 && (
                                <li className="p-6 text-sm text-muted-foreground">
                                    {t(
                                        filters.search || filters.unread
                                            ? 'messages.inbox.empty'
                                            : 'messages.inbox.empty_all',
                                    )}
                                </li>
                            )}
                        </ul>
                        <PaginationLinks links={threads.links} />
                    </section>
                    <section
                        aria-label={t('messages.inbox.conversation')}
                        className="min-w-0 rounded-xl border"
                    >
                        {conversation ? (
                            <>
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b p-4">
                                    <div className="min-w-0">
                                        <h2 className="font-semibold break-words">
                                            {conversation.customer
                                                ?.display_name ??
                                                t('messages.inbox.unknown')}
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            {phoneText(conversation.phone)}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild className="min-h-11">
                                            <a
                                                href={`tel:${conversation.phone}`}
                                            >
                                                <Phone />{' '}
                                                {t('messages.inbox.call')}
                                            </a>
                                        </Button>
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="min-h-11 lg:hidden"
                                        >
                                            <Link
                                                href={index({
                                                    query: {
                                                        search: filters.search,
                                                        unread: filters.unread
                                                            ? 1
                                                            : 0,
                                                    },
                                                })}
                                            >
                                                <ArrowLeft />{' '}
                                                {t('messages.inbox.back')}
                                            </Link>
                                        </Button>
                                        {conversation.customer && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                className="min-h-11"
                                            >
                                                <Link
                                                    href={customerShow(
                                                        conversation.customer
                                                            .id,
                                                    )}
                                                >
                                                    {t(
                                                        'messages.inbox.open_customer',
                                                    )}
                                                </Link>
                                            </Button>
                                        )}
                                        {!conversation.customer && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                className="min-h-11"
                                            >
                                                <Link href={customerCreate()}>
                                                    {t('customers.add')}
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                <div className="min-w-0 space-y-4 p-4">
                                    <p className="text-xs text-muted-foreground">
                                        {conversation.messages.total === 0
                                            ? t('messages.inbox.start')
                                            : t('messages.inbox.newest_last')}
                                    </p>
                                    {/* Older pages above the messages, the newest at the bottom. */}
                                    <PaginationLinks
                                        links={conversation.messages.links}
                                    />
                                    <MessageHistory
                                        messages={conversation.messages.data}
                                        showJobLinks
                                    />
                                </div>
                                <Reply
                                    key={conversation.phone}
                                    conversation={conversation}
                                />
                                {/* Room for the phone bottom navigation. */}
                                <div
                                    ref={conversationEnd}
                                    className="scroll-mb-28 lg:scroll-mb-4"
                                />
                            </>
                        ) : (
                            <div className="flex min-h-52 flex-col items-center justify-center gap-3 p-6 text-center text-muted-foreground">
                                <MessageSquare className="size-8" />
                                <p>{t('messages.inbox.select')}</p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

SmsInbox.layout = {
    breadcrumbs: [{ title: 'messages.inbox.title', href: index() }],
};
