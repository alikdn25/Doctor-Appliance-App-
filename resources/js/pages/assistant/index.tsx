import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    ClipboardCheck,
    Copy,
    ExternalLink,
    MessageSquarePlus,
    Search,
    Send,
    Sparkles,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { IconButton } from '@/components/ui/icon-button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { handQuickResult } from '@/lib/quick-booking';
import { cn } from '@/lib/utils';
import { ask, findProblems, newChat } from '@/routes/assistant';
import { cancel, confirm } from '@/routes/assistant/actions';
import { index as reviewsPage } from '@/routes/app-reviews';
import { create } from '@/routes/jobs';

type Message = {
    kind: 'message';
    id: string;
    from: 'user' | 'assistant';
    text: string;
    at: string;
};
type Action = {
    kind: 'action';
    id: string;
    action_id: number;
    type: string;
    summary: string;
    status: 'pending' | 'running' | 'confirmed' | 'cancelled' | 'failed';
    result: string | null;
    at: string;
};
type Problem = {
    severity: 'high' | 'medium' | 'low';
    kind: 'business' | 'technical';
    title: string;
    why: string;
    action: string;
    links: { type: string; id: number; label: string; url: string }[];
    claude_task: string | null;
};
type Problems = {
    kind: 'problems';
    id: string;
    summary: string;
    problems: Problem[];
    at: string;
};
type Item = Message | Action | Problems;

/** A ready task for Claude Code, one block, with Copy. */
function TaskBlock({ text }: { text: string }) {
    const t = useTrans();
    const [copied, setCopied] = useState(false);

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-muted-foreground">
                    {t('assistant.problems.claude_task')}
                </span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        void navigator.clipboard
                            ?.writeText(text)
                            .then(() => setCopied(true))
                            .catch(() => undefined)
                    }
                >
                    <Copy />{' '}
                    {copied
                        ? t('assistant.problems.copied')
                        : t('assistant.problems.copy')}
                </Button>
            </div>
            <pre className="max-h-64 overflow-auto rounded-xl bg-muted p-3 text-xs leading-snug whitespace-pre-wrap">
                {text}
            </pre>
        </div>
    );
}

const SEVERITY_CLASS: Record<Problem['severity'], string> = {
    high: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100',
    medium: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
    low: 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100',
};

/** The Find problems report: problems by importance, what to do, links, Claude Code tasks. */
function ProblemsCard({ item }: { item: Problems }) {
    const t = useTrans();

    return (
        <div className="da-card space-y-3 p-4" data-test="assistant-problems">
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {t('assistant.problems.title')}
            </p>
            <p className="text-[15px] leading-snug">{item.summary}</p>
            {item.problems.map((problem, index) => (
                <div
                    key={index}
                    className="space-y-2 border-t pt-3"
                    data-test="assistant-problem"
                >
                    <p className="flex items-start gap-2">
                        <span
                            className={cn(
                                'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold',
                                SEVERITY_CLASS[problem.severity],
                            )}
                        >
                            {t(
                                `assistant.problems.severity.${problem.severity}`,
                            )}
                        </span>
                        <span className="font-semibold">{problem.title}</span>
                    </p>
                    {problem.why && (
                        <p className="text-sm text-muted-foreground">
                            {problem.why}
                        </p>
                    )}
                    {problem.action && (
                        <p className="text-sm">
                            <span className="font-semibold">
                                {t('assistant.problems.action')}:
                            </span>{' '}
                            {problem.action}
                        </p>
                    )}
                    {problem.links.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {problem.links.map((link) => (
                                <Link
                                    key={link.url}
                                    href={link.url}
                                    className="da-chip da-press inline-flex min-h-11 items-center gap-1.5 rounded-2xl px-3 text-sm font-medium"
                                >
                                    <ExternalLink className="size-4" />
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                    )}
                    {problem.claude_task && (
                        <TaskBlock text={problem.claude_task} />
                    )}
                </div>
            ))}
        </div>
    );
}

const post = (url: string, body: Record<string, unknown> = {}) =>
    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

/** A proposed action: what will happen, then Confirm / Cancel. Nothing runs without Confirm. */
function ActionCard({
    item,
    onDecided,
}: {
    item: Action;
    onDecided: (action: Action) => void;
}) {
    const t = useTrans();
    const [busy, setBusy] = useState<'confirm' | 'cancel' | null>(null);

    const decide = (which: 'confirm' | 'cancel') => {
        if (busy) return;
        setBusy(which);
        post((which === 'confirm' ? confirm : cancel)(item.action_id).url)
            .then((response) => response.json())
            .then(
                (body: {
                    action: Action;
                    booking?: Record<string, unknown> | null;
                }) => {
                    if (body.action) onDecided(body.action);
                    // A new booking: the same filled form as Quick booking.
                    if (body.booking) {
                        handQuickResult(body.booking);
                        router.visit(
                            create({ query: { book: 1, quick: 1 } }).url,
                        );
                    }
                },
            )
            .catch(() => undefined)
            .finally(() => setBusy(null));
    };

    return (
        <div
            className="da-card space-y-3 border-amber-300 p-4"
            data-test="assistant-action"
            data-status={item.status}
        >
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {t('assistant.what_i_will_do')}
            </p>
            <p className="text-[15px] leading-snug">{item.summary}</p>
            {item.status === 'pending' ? (
                <div className="grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        size="lg"
                        loading={busy === 'cancel'}
                        disabled={busy !== null}
                        onClick={() => decide('cancel')}
                        data-test="assistant-cancel"
                    >
                        <X /> {t('assistant.cancel')}
                    </Button>
                    <Button
                        size="lg"
                        loading={busy === 'confirm'}
                        disabled={busy !== null}
                        onClick={() => decide('confirm')}
                        data-test="assistant-confirm"
                    >
                        <Check /> {t('assistant.confirm')}
                    </Button>
                </div>
            ) : (
                <p
                    className={cn(
                        'text-sm font-medium',
                        item.status === 'confirmed' && 'text-success',
                        item.status === 'failed' && 'text-danger',
                        item.status === 'cancelled' && 'text-muted-foreground',
                    )}
                >
                    {item.result ?? t(`assistant.statuses.${item.status}`)}
                </p>
            )}
        </div>
    );
}

/**
 * The AI assistant (More → Assistant, Owner only), laid out like the SMS chat: questions on the right, answers on the
 * left, the composer pinned at the bottom (the keyboard microphone dictates). Actions come as cards with Confirm and
 * Cancel.
 */
export default function AssistantIndex({
    conversation: initialConversation,
    items: initialItems,
    configured,
}: {
    conversation: string;
    items: Item[];
    configured: boolean;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const [conversation, setConversation] = useState(initialConversation);
    const [items, setItems] = useState<Item[]>(initialItems);
    const [question, setQuestion] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(
        configured ? null : t('assistant.not_set_up'),
    );
    const end = useRef<HTMLDivElement>(null);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [items, pending]);

    const [checking, setChecking] = useState(false);
    const runFindProblems = () => {
        if (pending !== null || checking) return;
        setChecking(true);
        setError(null);
        post(findProblems().url, { conversation })
            .then(async (response) => {
                const body = await response.json().catch(() => null);
                if (body?.conversation) setConversation(body.conversation);
                if (body?.items) setItems(body.items);
                if (!response.ok) {
                    setError(body?.message ?? t('assistant.failed'));
                }
            })
            .catch(() => setError(t('assistant.failed')))
            .finally(() => setChecking(false));
    };
    const send = (text: string) => {
        const value = text.trim();
        if (value === '' || pending !== null || checking) return;
        setPending(value);
        setQuestion('');
        setError(null);
        post(ask().url, { question: value, conversation })
            .then(async (response) => {
                const body = await response.json().catch(() => null);
                if (body?.conversation) setConversation(body.conversation);
                if (body?.items) setItems(body.items);
                if (!response.ok) {
                    setError(body?.message ?? t('assistant.failed'));
                }
            })
            .catch(() => {
                setError(t('assistant.failed'));
                setQuestion(value);
            })
            .finally(() => setPending(null));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(question);
    };
    const startNew = () => {
        post(newChat().url)
            .then((response) => response.json())
            .then((body: { conversation: string }) => {
                setConversation(body.conversation);
                setItems([]);
                setError(null);
            })
            .catch(() => undefined);
    };
    const decided = (action: Action) =>
        setItems((list) =>
            list.map((item) =>
                item.kind === 'action' && item.action_id === action.action_id
                    ? action
                    : item,
            ),
        );

    return (
        <>
            <Head title={t('assistant.title')} />
            <ScreenHeader
                title={t('assistant.title')}
                subtitle={t('assistant.subtitle')}
                actions={
                    <button
                        type="button"
                        className={headerButtonClass}
                        aria-label={t('assistant.new_chat')}
                        onClick={startNew}
                        data-test="assistant-new"
                    >
                        <MessageSquarePlus className="size-5" />
                    </button>
                }
            />

            <section
                aria-label={t('assistant.title')}
                className="mx-auto flex min-h-[calc(100dvh-9rem)] w-full max-w-3xl flex-col px-4 pt-3 sm:px-6"
            >
                <div className="flex flex-wrap gap-2 pb-3">
                    <Button
                        type="button"
                        variant="outline"
                        loading={checking}
                        disabled={pending !== null}
                        onClick={runFindProblems}
                        data-test="assistant-find-problems"
                    >
                        <Search /> {t('assistant.problems.button')}
                    </Button>
                    {auth.can?.runAppReview && (
                        <Button
                            type="button"
                            variant="outline"
                            asChild
                            data-test="assistant-app-review"
                        >
                            <Link href={reviewsPage()}>
                                <ClipboardCheck />{' '}
                                {t('assistant.review.button')}
                            </Link>
                        </Button>
                    )}
                </div>
                <div className="flex-1 space-y-3" data-test="assistant-thread">
                    {items.length === 0 && pending === null && (
                        <div className="space-y-3 py-6">
                            <p className="text-center text-sm text-muted-foreground">
                                {t('assistant.empty')}
                            </p>
                            <div className="flex flex-wrap justify-center gap-2">
                                {[0, 1, 2, 3].map((i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        className="da-chip da-press min-h-11 rounded-2xl px-3 text-sm font-medium"
                                        onClick={() =>
                                            send(t(`assistant.examples.${i}`))
                                        }
                                    >
                                        {t(`assistant.examples.${i}`)}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                    {items.map((item) =>
                        item.kind === 'problems' ? (
                            <ProblemsCard key={item.id} item={item} />
                        ) : item.kind === 'action' ? (
                            <ActionCard
                                key={item.id}
                                item={item}
                                onDecided={decided}
                            />
                        ) : (
                            <div
                                key={item.id}
                                className={cn(
                                    'flex',
                                    item.from === 'user'
                                        ? 'justify-end'
                                        : 'justify-start',
                                )}
                            >
                                <div
                                    data-from={item.from}
                                    className={cn(
                                        'max-w-[85%] rounded-2xl px-3.5 py-2 text-[15px] leading-snug [overflow-wrap:anywhere] whitespace-pre-line shadow-[0_1px_2px_rgb(16_42_79/0.12)] sm:max-w-[70%]',
                                        item.from === 'user'
                                            ? 'da-primary rounded-br-md'
                                            : 'rounded-bl-md border bg-card',
                                    )}
                                >
                                    {item.text}
                                </div>
                            </div>
                        ),
                    )}
                    {pending !== null && (
                        <>
                            <div className="flex justify-end">
                                <div className="da-primary max-w-[85%] rounded-2xl rounded-br-md px-3.5 py-2 text-[15px] leading-snug whitespace-pre-line">
                                    {pending}
                                </div>
                            </div>
                            <p
                                role="status"
                                className="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <Sparkles className="size-4 animate-pulse" />
                                {t('assistant.thinking')}
                            </p>
                        </>
                    )}
                    {checking && (
                        <p
                            role="status"
                            className="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Sparkles className="size-4 animate-pulse" />
                            {t('assistant.problems.running')}
                        </p>
                    )}
                    {error && (
                        <p
                            role="alert"
                            className="rounded-2xl border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                            data-test="assistant-error"
                        >
                            {error}
                        </p>
                    )}
                </div>
                <div className="h-3" ref={end} />
                <form
                    onSubmit={submit}
                    className="da-pinned da-action-bar sticky bottom-0 z-30 -mx-4 border-t p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:-mx-6 md:mx-0 md:rounded-2xl md:border"
                >
                    <div className="flex items-end gap-2">
                        <Label htmlFor="assistant-question" className="sr-only">
                            {t('assistant.placeholder')}
                        </Label>
                        <Textarea
                            id="assistant-question"
                            value={question}
                            onChange={(event) =>
                                setQuestion(event.target.value)
                            }
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' &&
                                    !event.shiftKey &&
                                    window.matchMedia('(pointer: fine)').matches
                                ) {
                                    event.preventDefault();
                                    send(question);
                                }
                            }}
                            maxLength={2000}
                            rows={1}
                            placeholder={t('assistant.placeholder')}
                            className="[field-sizing:content] max-h-40 min-h-11 flex-1 resize-none"
                            data-test="assistant-question"
                        />
                        <IconButton
                            type="submit"
                            label={t('assistant.send')}
                            variant="primary"
                            disabled={
                                question.trim() === '' || pending !== null
                            }
                            data-test="assistant-send"
                        >
                            <Send />
                        </IconButton>
                    </div>
                </form>
            </section>
        </>
    );
}
