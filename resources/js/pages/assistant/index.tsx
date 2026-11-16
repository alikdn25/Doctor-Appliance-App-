import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    ClipboardCheck,
    Copy,
    ExternalLink,
    MessageSquarePlus,
    Paperclip,
    Pencil,
    Search,
    Send,
    Sparkles,
    X,
} from 'lucide-react';
import type { ChangeEvent, FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { useMoney } from '@/components/billing/money';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { ChatDock } from '@/components/ui/chat-dock';
import { IconButton } from '@/components/ui/icon-button';
import { Label } from '@/components/ui/label';
import { MicButton } from '@/components/ui/mic-button';
import { Textarea } from '@/components/ui/textarea';
import { scrollToPageEnd } from '@/hooks/use-viewport-keyboard';
import { xsrfToken } from '@/lib/http';
import { compressImage } from '@/lib/image';
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
    photos?: string[];
    at: string;
};
type InvoicePreview = {
    customer: {
        id: number | null;
        matched_by: 'phone' | 'email' | null;
        name: string;
        phone: string;
        email: string | null;
        address: {
            line1: string;
            unit?: string;
            city?: string;
            region?: string;
            postal_code?: string;
        } | null;
    };
    job_number: number | null;
    lines: { description: string; unit_price: number; total: number }[];
    taxes: { name: string; rate: string; amount: number }[];
    subtotal: number;
    tax_total: number;
    total: number;
    currency: string;
    prices_include_tax: boolean;
    amounts_include_tax: boolean;
    notes: string | null;
    photos: number;
};
type Action = {
    kind: 'action';
    id: string;
    action_id: number;
    type: string;
    summary: string;
    status: 'pending' | 'running' | 'confirmed' | 'cancelled' | 'failed';
    result: string | null;
    invoice?: InvoicePreview | null;
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

/** The decided card's result line. */
function ActionResult({ item }: { item: Action }) {
    const t = useTrans();

    return (
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
    );
}

/**
 * The invoice the assistant read (create_invoice), before anything is saved: customer, phone, email, address, lines,
 * taxes and total as calculated by the app. Confirm & send saves and sends it; Edit saves it and opens the editor;
 * Cancel drops it.
 */
function InvoiceCard({
    item,
    invoice,
    onDecided,
}: {
    item: Action;
    invoice: InvoicePreview;
    onDecided: (action: Action) => void;
}) {
    const t = useTrans();
    const money = useMoney(invoice.currency);
    const [busy, setBusy] = useState<'send' | 'edit' | 'cancel' | null>(null);
    const address = invoice.customer.address
        ? [
              [invoice.customer.address.line1, invoice.customer.address.unit]
                  .filter(Boolean)
                  .join(', '),
              invoice.customer.address.city,
              [
                  invoice.customer.address.region,
                  invoice.customer.address.postal_code,
              ]
                  .filter(Boolean)
                  .join(' '),
          ]
              .filter(Boolean)
              .join(', ')
        : null;

    const decide = (which: 'send' | 'edit' | 'cancel') => {
        if (busy) return;
        setBusy(which);
        post(
            (which === 'cancel' ? cancel : confirm)(item.action_id).url,
            which === 'cancel' ? {} : { mode: which },
        )
            .then((response) => response.json())
            .then((body: { action?: Action; redirect?: string | null }) => {
                if (body.action) onDecided(body.action);
                if (body.redirect) router.visit(body.redirect);
            })
            .catch(() => undefined)
            .finally(() => setBusy(null));
    };

    return (
        <div
            className="da-card space-y-3 border-amber-300 p-4"
            data-test="assistant-invoice"
            data-status={item.status}
        >
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {t('assistant.invoice.title')}
            </p>
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt className="text-muted-foreground">
                    {t('assistant.invoice.customer')}
                </dt>
                <dd className="font-semibold">
                    {invoice.customer.name}{' '}
                    <span className="text-xs font-normal text-muted-foreground">
                        (
                        {invoice.customer.id
                            ? t('assistant.invoice.existing')
                            : t('assistant.invoice.new_customer')}
                        )
                    </span>
                </dd>
                <dt className="text-muted-foreground">
                    {t('assistant.invoice.phone')}
                </dt>
                <dd>{invoice.customer.phone}</dd>
                {invoice.customer.email && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('assistant.invoice.email')}
                        </dt>
                        <dd className="[overflow-wrap:anywhere]">
                            {invoice.customer.email}
                        </dd>
                    </>
                )}
                {address && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('assistant.invoice.address')}
                        </dt>
                        <dd>{address}</dd>
                    </>
                )}
            </dl>
            <ul className="divide-y border-y text-sm" data-test="invoice-lines">
                {invoice.lines.map((line, index) => (
                    <li key={index} className="flex justify-between gap-3 py-2">
                        <span>{line.description}</span>
                        <span className="font-medium tabular-nums">
                            {money(line.total)}
                        </span>
                    </li>
                ))}
            </ul>
            <dl className="space-y-1 text-sm">
                <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">
                        {t('assistant.invoice.subtotal')}
                    </dt>
                    <dd className="tabular-nums">{money(invoice.subtotal)}</dd>
                </div>
                {invoice.taxes.map((tax) => (
                    <div key={tax.name} className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">
                            {tax.name} {tax.rate}%
                        </dt>
                        <dd className="tabular-nums">{money(tax.amount)}</dd>
                    </div>
                ))}
                <div className="flex justify-between gap-3 text-base font-bold">
                    <dt>{t('assistant.invoice.total')}</dt>
                    <dd className="tabular-nums" data-test="invoice-total">
                        {money(invoice.total)}
                    </dd>
                </div>
            </dl>
            <div className="space-y-1 text-xs text-muted-foreground">
                {invoice.prices_include_tax && (
                    <p>{t('assistant.invoice.tax_included')}</p>
                )}
                {invoice.amounts_include_tax && !invoice.prices_include_tax && (
                    <p>{t('assistant.invoice.from_total_with_tax')}</p>
                )}
                <p>
                    {invoice.job_number
                        ? t('assistant.invoice.job', {
                              number: invoice.job_number,
                          })
                        : t('assistant.invoice.new_job')}
                </p>
                {invoice.photos > 0 && (
                    <p>
                        {t('assistant.invoice.photos', {
                            count: invoice.photos,
                        })}
                    </p>
                )}
                {invoice.notes && <p>{invoice.notes}</p>}
            </div>
            {item.status === 'pending' ? (
                <div className="space-y-2">
                    <Button
                        size="lg"
                        className="w-full"
                        loading={busy === 'send'}
                        disabled={busy !== null}
                        onClick={() => decide('send')}
                        data-test="invoice-confirm-send"
                    >
                        <Send /> {t('assistant.invoice.confirm_send')}
                    </Button>
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            variant="outline"
                            size="lg"
                            loading={busy === 'edit'}
                            disabled={busy !== null}
                            onClick={() => decide('edit')}
                            data-test="invoice-edit"
                        >
                            <Pencil /> {t('assistant.invoice.edit')}
                        </Button>
                        <Button
                            variant="outline"
                            size="lg"
                            loading={busy === 'cancel'}
                            disabled={busy !== null}
                            onClick={() => decide('cancel')}
                            data-test="invoice-cancel"
                        >
                            <X /> {t('assistant.cancel')}
                        </Button>
                    </div>
                </div>
            ) : (
                <ActionResult item={item} />
            )}
        </div>
    );
}

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
                <ActionResult item={item} />
            )}
        </div>
    );
}

const MAX_PHOTOS = 5;

/** Photos sent with a question, small, in the bubble. */
function PhotoStrip({ urls }: { urls: string[] }) {
    const t = useTrans();

    return (
        <div className="mb-1.5 flex flex-wrap gap-1.5">
            {urls.map((url, index) => (
                <a key={url} href={url} target="_blank" rel="noreferrer">
                    <img
                        src={url}
                        alt={t('assistant.photo', { number: index + 1 })}
                        className="size-20 rounded-lg object-cover"
                        loading="lazy"
                    />
                </a>
            ))}
        </div>
    );
}

/**
 * The AI assistant (More → Assistant, Owner only), laid out like the SMS chat: questions on the right, answers on the
 * left, the composer docked at the bottom: right above the bottom bar, or right on top of the keyboard while typing.
 * The microphone dictates (EN / RU), the paperclip attaches up to 5 photos (e.g. an invoice to read). Actions come as
 * cards with Confirm and Cancel; an invoice comes as a preview with Confirm & send, Edit and Cancel.
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
    // Photos to send with the question (shrunk on the phone to 1600 px), with their previews.
    const [photos, setPhotos] = useState<
        { id: string; blob: Blob; filename: string; preview: string }[]
    >([]);
    const [sentPhotos, setSentPhotos] = useState<string[]>([]);
    const picker = useRef<HTMLInputElement>(null);
    const latestPhotos = useRef(photos);
    latestPhotos.current = photos;

    useEffect(() => {
        scrollToPageEnd();
    }, [items, pending]);

    // Previews are freed when the screen goes.
    useEffect(
        () => () =>
            latestPhotos.current.forEach((photo) =>
                URL.revokeObjectURL(photo.preview),
            ),
        [],
    );

    const addPhotos = async (event: ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(event.target.files ?? []);
        event.target.value = '';
        const room = MAX_PHOTOS - photos.length;
        if (files.length > room) {
            setError(t('assistant.attach_limit', { count: MAX_PHOTOS }));
        }
        const added = await Promise.all(
            files.slice(0, Math.max(0, room)).map(async (file) => {
                const { blob, filename } = await compressImage(file, 1600);

                return {
                    id: `${Date.now()}-${Math.random()}`,
                    blob,
                    filename,
                    preview: URL.createObjectURL(blob),
                };
            }),
        );
        setPhotos((list) => [...list, ...added].slice(0, MAX_PHOTOS));
    };
    const removePhoto = (id: string) =>
        setPhotos((list) => {
            const photo = list.find((p) => p.id === id);
            if (photo) URL.revokeObjectURL(photo.preview);

            return list.filter((p) => p.id !== id);
        });

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
        const attached = photos;
        if (
            (value === '' && attached.length === 0) ||
            pending !== null ||
            checking
        ) {
            return;
        }
        setPending(value);
        setSentPhotos(attached.map((photo) => photo.preview));
        setQuestion('');
        setPhotos([]);
        setError(null);

        const body = new FormData();
        body.append('question', value);
        body.append('conversation', conversation);
        attached.forEach((photo) =>
            body.append('photos[]', photo.blob, photo.filename),
        );

        fetch(ask().url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body,
        })
            .then(async (response) => {
                const result = await response.json().catch(() => null);
                if (result?.conversation) setConversation(result.conversation);
                if (result?.items) setItems(result.items);
                if (!response.ok) {
                    setError(
                        response.status === 422
                            ? t('assistant.photo_failed')
                            : (result?.message ?? t('assistant.failed')),
                    );
                    if (response.status === 422) {
                        setQuestion(value);
                        setPhotos(attached);
                    }
                } else {
                    attached.forEach((photo) =>
                        URL.revokeObjectURL(photo.preview),
                    );
                }
            })
            .catch(() => {
                setError(t('assistant.failed'));
                setQuestion(value);
                setPhotos(attached);
            })
            .finally(() => {
                setPending(null);
                setSentPhotos([]);
            });
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
                className="mx-auto flex min-h-[calc(var(--app-vvh,100dvh)-9rem)] w-full max-w-3xl flex-col px-4 pt-3 sm:px-6"
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
                        ) : item.kind === 'action' && item.invoice ? (
                            <InvoiceCard
                                key={item.id}
                                item={item}
                                invoice={item.invoice}
                                onDecided={decided}
                            />
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
                                    {item.photos && item.photos.length > 0 && (
                                        <PhotoStrip urls={item.photos} />
                                    )}
                                    {item.text}
                                </div>
                            </div>
                        ),
                    )}
                    {pending !== null && (
                        <>
                            <div className="flex justify-end">
                                <div className="da-primary max-w-[85%] rounded-2xl rounded-br-md px-3.5 py-2 text-[15px] leading-snug whitespace-pre-line">
                                    {sentPhotos.length > 0 && (
                                        <PhotoStrip urls={sentPhotos} />
                                    )}
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
                <div className="h-3" />
                <ChatDock>
                    <form onSubmit={submit} className="space-y-2">
                        {photos.length > 0 && (
                            <div
                                className="flex gap-2 overflow-x-auto pt-1"
                                data-test="assistant-photos"
                            >
                                {photos.map((photo, index) => (
                                    <div
                                        key={photo.id}
                                        className="relative size-16 shrink-0"
                                    >
                                        <img
                                            src={photo.preview}
                                            alt={t('assistant.photo', {
                                                number: index + 1,
                                            })}
                                            className="size-16 rounded-xl border object-cover"
                                        />
                                        <button
                                            type="button"
                                            onClick={() =>
                                                removePhoto(photo.id)
                                            }
                                            className="absolute -top-1.5 -right-1.5 inline-flex size-7 items-center justify-center rounded-full bg-slate-900 text-white shadow"
                                            aria-label={t(
                                                'assistant.remove_photo',
                                            )}
                                            data-test="assistant-photo-remove"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="flex items-end gap-2">
                            <input
                                ref={picker}
                                type="file"
                                accept="image/*"
                                multiple
                                className="hidden"
                                onChange={(event) => void addPhotos(event)}
                                data-test="assistant-photo-input"
                            />
                            <IconButton
                                label={t('assistant.attach')}
                                variant="plain"
                                disabled={
                                    photos.length >= MAX_PHOTOS ||
                                    pending !== null
                                }
                                onClick={() => picker.current?.click()}
                                data-test="assistant-attach"
                            >
                                <Paperclip />
                            </IconButton>
                            <Label
                                htmlFor="assistant-question"
                                className="sr-only"
                            >
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
                                        window.matchMedia('(pointer: fine)')
                                            .matches
                                    ) {
                                        event.preventDefault();
                                        send(question);
                                    }
                                }}
                                maxLength={2000}
                                rows={1}
                                placeholder={t('assistant.placeholder')}
                                className="[field-sizing:content] max-h-40 min-h-11 min-w-0 flex-1 resize-none"
                                data-test="assistant-question"
                            />
                            <MicButton
                                value={question}
                                onChange={setQuestion}
                                disabled={pending !== null}
                            />
                            <IconButton
                                type="submit"
                                label={t('assistant.send')}
                                variant="primary"
                                disabled={
                                    (question.trim() === '' &&
                                        photos.length === 0) ||
                                    pending !== null
                                }
                                data-test="assistant-send"
                            >
                                <Send />
                            </IconButton>
                        </div>
                    </form>
                </ChatDock>
            </section>
        </>
    );
}
