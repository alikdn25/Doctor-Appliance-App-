import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { ArrowLeft, MessageSquare, Send } from 'lucide-react';
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
    customer: { id: number; display_name: string } | null;
    blocked: string | null;
    messages: Paginated<MessageItem>;
    unread_ids: number[];
};
type Filters = { search: string; unread: boolean };

function Reply({ conversation }: { conversation: Conversation }) {
    const t = useTrans();
    const form = useForm({ phone: conversation.phone, body: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(send().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3 border-t p-4">
            <Label htmlFor="sms-reply">{t('messages.inbox.reply')}</Label>
            {conversation.blocked && (
                <p role="status" className="text-sm text-muted-foreground">
                    {conversation.blocked}
                </p>
            )}
            <Textarea
                id="sms-reply"
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                maxLength={1600}
                rows={3}
                disabled={conversation.blocked !== null}
                placeholder={t('messages.inbox.reply_placeholder')}
            />
            <InputError message={form.errors.body ?? form.errors.phone} />
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">
                    {t('messages.inbox.quiet_hint')}
                </p>
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={
                        form.processing ||
                        conversation.blocked !== null ||
                        form.data.body.trim() === ''
                    }
                >
                    <Send /> {t('messages.send_sms')}
                </Button>
            </div>
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
    const lastRead = useRef('');
    usePoll(15000, { only: ['threads', 'conversation', 'unreadMessages'] });
    const phone = conversation?.phone;
    const unreadIds = conversation?.unread_ids.join(',') ?? '';

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
                                    {t('messages.inbox.empty')}
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
                                        {t('messages.inbox.newest_first')}
                                    </p>
                                    <MessageHistory
                                        messages={conversation.messages.data}
                                        showJobLinks
                                    />
                                    <PaginationLinks
                                        links={conversation.messages.links}
                                    />
                                </div>
                                <Reply
                                    key={conversation.phone}
                                    conversation={conversation}
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
