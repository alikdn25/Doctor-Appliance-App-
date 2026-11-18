import { router } from '@inertiajs/react';
import { Check, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import type { AiCard } from '@/components/messaging/types';
import { Button } from '@/components/ui/button';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { handQuickResult } from '@/lib/quick-booking';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { create } from '@/routes/jobs';
import { confirm, dismiss, plate } from '@/routes/messages/ai';

type Result = {
    message?: string;
    booking?: Record<string, unknown> | null;
    card?: AiCard;
    errors?: Record<string, string[]>;
};

/**
 * What the AI read from an incoming text (owner's task of November 18), under the message: the urgent mark, the
 * proposed actions (tap → preview → Confirm), a rating plate read from a photo, and the reply draft. Nothing runs
 * without Confirm; the draft only goes into the reply box.
 */
export function AiMessageCard({
    messageId,
    card: initial,
    onUseDraft,
}: {
    messageId: number;
    card: AiCard;
    onUseDraft: (text: string) => void;
}) {
    const t = useTrans();
    const [card, setCard] = useState(initial);
    const [open, setOpen] = useState<number | 'plate' | null>(null);
    const [busy, setBusy] = useState(false);

    const call = async (url: string, data: Record<string, unknown> = {}) => {
        if (busy) return;
        setBusy(true);

        try {
            const response = await sendJson(url, 'POST', data);
            const body = (await response.json()) as Result;

            if (!response.ok) {
                toast.error(
                    Object.values(body.errors ?? {})[0]?.[0] ??
                        body.message ??
                        t('automation.inbox.failed'),
                );

                return;
            }

            if (body.card) setCard(body.card);
            setOpen(null);
            if (body.message) toast.success(body.message);

            if (body.booking) {
                handQuickResult(body.booking);
                router.visit(create({ query: { book: 1, quick: 1 } }).url);

                return;
            }

            router.reload({ only: ['conversation'] });
        } finally {
            setBusy(false);
        }
    };

    const pending = card.actions.filter((a) => a.status === 'pending');
    const decided = card.actions.filter((a) => a.status !== 'pending');
    const hasContent =
        card.urgent ||
        card.actions.length > 0 ||
        card.plate !== null ||
        !!card.reply_draft;

    if (!hasContent) {
        return null;
    }

    return (
        <div
            className="da-card mt-1 w-full min-w-64 space-y-2 border-violet-200 p-3 text-left text-sm"
            data-test="ai-card"
        >
            <div className="flex items-center gap-2">
                <Sparkles className="size-4 text-violet-600" aria-hidden />
                <span className="font-semibold">
                    {t('automation.inbox.ai_title')}
                </span>
                {card.urgent && (
                    <span
                        className="rounded-full bg-red-600 px-2 text-xs leading-5 font-bold text-white"
                        data-test="urgent-label"
                    >
                        {t('automation.inbox.urgent')}
                    </span>
                )}
            </div>

            {pending.map((action) => (
                <div key={action.index} className="space-y-2">
                    <Button
                        variant="outline"
                        className="h-11 w-full justify-start"
                        onClick={() =>
                            setOpen(open === action.index ? null : action.index)
                        }
                        aria-expanded={open === action.index}
                        data-test={`ai-action-${action.type}`}
                    >
                        {t(`automation.inbox.actions.${action.type}`)}
                    </Button>
                    {open === action.index && (
                        <div className="space-y-2 rounded-xl bg-muted/60 p-3">
                            <p data-test="ai-preview">{action.preview}</p>
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        void call(
                                            dismiss([messageId, action.index])
                                                .url,
                                        )
                                    }
                                >
                                    <X /> {t('automation.inbox.dismiss')}
                                </Button>
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        void call(
                                            confirm([messageId, action.index])
                                                .url,
                                        )
                                    }
                                    data-test="ai-confirm"
                                >
                                    <Check /> {t('automation.inbox.confirm')}
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            ))}

            {decided.map((action) => (
                <p
                    key={action.index}
                    className="text-xs text-muted-foreground"
                    data-status={action.status}
                >
                    {t(`automation.inbox.actions.${action.type}`)} —{' '}
                    {t(`automation.inbox.status.${action.status}`)}
                </p>
            ))}

            {card.plate && (
                <div className="space-y-2 rounded-xl bg-muted/60 p-3">
                    <p className="font-medium">
                        {t('automation.inbox.plate_title')}
                    </p>
                    <p data-test="plate-preview">{card.plate.preview}</p>
                    {card.plate.status === 'pending' && card.plate.job_id ? (
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() =>
                                    void call(plate(messageId).url, {
                                        dismiss: true,
                                    })
                                }
                            >
                                <X /> {t('automation.inbox.dismiss')}
                            </Button>
                            <Button
                                disabled={busy}
                                onClick={() => void call(plate(messageId).url)}
                                data-test="plate-confirm"
                            >
                                <Check /> {t('automation.inbox.plate_attach')}
                            </Button>
                        </div>
                    ) : (
                        card.plate.status !== 'pending' && (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    `automation.inbox.status.${card.plate.status}`,
                                )}
                            </p>
                        )
                    )}
                </div>
            )}

            {card.reply_draft && (
                <button
                    type="button"
                    onClick={() => onUseDraft(card.reply_draft!)}
                    className={cn(
                        'da-press w-full rounded-xl border border-dashed p-2 text-left',
                    )}
                    data-test="use-draft"
                >
                    <span className="block text-xs font-semibold text-violet-700">
                        {t('automation.inbox.use_draft')}
                    </span>
                    <span className="line-clamp-3">{card.reply_draft}</span>
                </button>
            )}
        </div>
    );
}
