import { router } from '@inertiajs/react';
import { ArrowLeft, Check, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useMoney } from '@/components/billing/money';
import { TaxIncludedSwitch } from '@/components/billing/tax-included-switch';
import { Button } from '@/components/ui/button';
import { MicButton } from '@/components/ui/mic-button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { voiceClose } from '@/routes/jobs';
import { preview as previewClose, read } from '@/routes/jobs/voice-close';

type Draft = {
    note: string;
    lines: {
        description: string;
        amount: number;
        kind: 'service' | 'part';
        tax_rate_ids: number[];
    }[];
    amounts_include_tax: boolean;
    payment: 'card' | 'cash' | 'e_transfer' | 'unpaid';
    status: 'completed' | 'need_to_order_parts';
    part_name: string;
};

type Tax = { id: number; name: string; rate: string };

type Money = {
    lines: { description: string; total: number }[];
    taxes: {
        tax_rate_id: number;
        name: string;
        rate: string;
        amount: number;
    }[];
    subtotal: number;
    total: number;
    currency: string;
};

/**
 * "Close by voice" (owner's task of November 18): the technician says what was done, the prices, how the customer paid
 * and whether a part is needed (microphone, like the assistant). The AI fills it in, one line per piece of work, part
 * or material; each line has a tick per company tax (the first ticked), changed here; the app works out taxes and the
 * total; the preview is checked and only Confirm saves the note, the invoice, the payment and the status.
 */
export function VoiceCloseSheet({
    open,
    onOpenChange,
    jobId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
}) {
    const t = useTrans();
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [preview, setPreview] = useState<{
        draft: Draft;
        money: Money;
    } | null>(null);
    const [taxes, setTaxes] = useState<Tax[]>([]);
    const money = useMoney(preview?.money.currency);

    useEffect(() => {
        if (open) {
            setPreview(null);
            setError(null);
        }
    }, [open]);

    const readText = async () => {
        if (text.trim() === '' || busy) return;
        setBusy(true);
        setError(null);
        try {
            const response = await sendJson(read(jobId).url, 'POST', { text });
            const body = (await response.json()) as {
                draft?: Draft;
                money?: Money;
                taxes?: Tax[];
                message?: string;
            };
            if (!response.ok || !body.draft || !body.money) {
                setError(body.message ?? t('automation.inbox.failed'));

                return;
            }
            setTaxes(body.taxes ?? []);
            setPreview({ draft: body.draft, money: body.money });
        } catch {
            setError(t('automation.inbox.failed'));
        } finally {
            setBusy(false);
        }
    };

    // A tick or "Price includes tax" changed: the money again (code).
    const recalc = async (draft: Draft) => {
        if (!preview || busy) return;
        setPreview({ ...preview, draft });
        try {
            const response = await sendJson(previewClose(jobId).url, 'POST', {
                lines: draft.lines,
                amounts_include_tax: draft.amounts_include_tax,
            });
            const body = (await response.json()) as { money?: Money };
            if (response.ok && body.money) {
                setPreview({ draft, money: body.money });
            }
        } catch {
            setError(t('automation.inbox.failed'));
        }
    };

    const toggleTax = (index: number, id: number) => {
        if (!preview) return;
        const lines = preview.draft.lines.map((line, i) =>
            i !== index
                ? line
                : {
                      ...line,
                      tax_rate_ids: line.tax_rate_ids.includes(id)
                          ? line.tax_rate_ids.filter((x) => x !== id)
                          : [...line.tax_rate_ids, id],
                  },
        );
        void recalc({ ...preview.draft, lines });
    };

    const confirm = () => {
        if (!preview) return;
        router.post(voiceClose(jobId).url, preview.draft as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setText('');
                onOpenChange(false);
            },
            onError: (errors) =>
                setError(String(Object.values(errors)[0] ?? '')),
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[90dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="voice-close-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{t('automation.voice_close.title')}</SheetTitle>
                    <SheetDescription>
                        {t('automation.voice_close.hint')}
                    </SheetDescription>
                </SheetHeader>

                {preview === null ? (
                    <>
                        <div className="flex items-end gap-2">
                            <Textarea
                                id="voice-close-text"
                                value={text}
                                rows={4}
                                maxLength={5000}
                                onChange={(e) => setText(e.target.value)}
                                className="min-w-0 flex-1"
                                aria-label={t('automation.voice_close.title')}
                            />
                            <MicButton value={text} onChange={setText} />
                        </div>
                        <Button
                            size="lg"
                            className="w-full"
                            loading={busy}
                            disabled={text.trim() === ''}
                            onClick={() => void readText()}
                            data-test="voice-close-read"
                        >
                            <Sparkles /> {t('automation.voice_close.read')}
                        </Button>
                    </>
                ) : (
                    <div
                        className="space-y-3 text-sm"
                        data-test="voice-close-preview"
                    >
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {t('automation.voice_close.preview')}
                        </p>
                        {preview.draft.note && (
                            <div>
                                <p className="font-semibold">
                                    {t('automation.voice_close.note')}
                                </p>
                                <p className="whitespace-pre-line">
                                    {preview.draft.note}
                                </p>
                            </div>
                        )}
                        <div className="da-card space-y-1 p-3">
                            <p className="font-semibold">
                                {t('automation.voice_close.lines')}
                            </p>
                            {preview.money.lines.length === 0 ? (
                                <p className="text-muted-foreground">
                                    {t('automation.voice_close.no_lines')}
                                </p>
                            ) : (
                                <>
                                    {preview.money.lines.map((line, i) => (
                                        <div
                                            key={i}
                                            className="space-y-1.5 border-b pb-2 last:border-b-0"
                                            data-test="voice-close-line"
                                        >
                                            <p className="flex justify-between gap-2">
                                                <span>{line.description}</span>
                                                <span className="tabular-nums">
                                                    {money(line.total)}
                                                </span>
                                            </p>
                                            {taxes.length > 0 && (
                                                <div
                                                    className="flex flex-wrap gap-2"
                                                    role="group"
                                                    aria-label={t(
                                                        'charges.new.line_taxes',
                                                    )}
                                                >
                                                    {taxes.map((tax) => {
                                                        const on =
                                                            preview.draft.lines[
                                                                i
                                                            ]?.tax_rate_ids.includes(
                                                                tax.id,
                                                            ) ?? false;

                                                        return (
                                                            <button
                                                                key={tax.id}
                                                                type="button"
                                                                role="checkbox"
                                                                aria-checked={
                                                                    on
                                                                }
                                                                onClick={() =>
                                                                    toggleTax(
                                                                        i,
                                                                        tax.id,
                                                                    )
                                                                }
                                                                className={cn(
                                                                    'da-chip da-press inline-flex min-h-11 items-center gap-2 rounded-2xl px-3 text-sm font-semibold',
                                                                    on &&
                                                                        'text-primary ring-2 ring-primary',
                                                                )}
                                                                data-test={`voice-close-tax-${tax.id}`}
                                                            >
                                                                <span
                                                                    className={cn(
                                                                        'flex size-6 shrink-0 items-center justify-center rounded-md border-2',
                                                                        on
                                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                                            : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-transparent',
                                                                    )}
                                                                >
                                                                    {on && (
                                                                        <Check className="size-4" />
                                                                    )}
                                                                </span>
                                                                {tax.name}{' '}
                                                                {tax.rate}%
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                    <TaxIncludedSwitch
                                        checked={
                                            preview.draft.amounts_include_tax
                                        }
                                        onChange={(on) =>
                                            void recalc({
                                                ...preview.draft,
                                                amounts_include_tax: on,
                                            })
                                        }
                                        testId="voice-close-price-includes-tax"
                                    />
                                    <p className="flex justify-between gap-2 border-t pt-1 text-muted-foreground">
                                        <span>
                                            {t(
                                                'automation.voice_close.subtotal',
                                            )}
                                        </span>
                                        <span className="tabular-nums">
                                            {money(preview.money.subtotal)}
                                        </span>
                                    </p>
                                    {preview.money.taxes.map((tax) => (
                                        <p
                                            key={tax.tax_rate_id}
                                            className="flex justify-between gap-2 text-muted-foreground"
                                        >
                                            <span>
                                                {tax.name} {tax.rate}%
                                            </span>
                                            <span className="tabular-nums">
                                                {money(tax.amount)}
                                            </span>
                                        </p>
                                    ))}
                                    <p
                                        className="flex justify-between gap-2 font-bold"
                                        data-test="voice-close-total"
                                    >
                                        <span>
                                            {t('automation.voice_close.total')}
                                        </span>
                                        <span className="tabular-nums">
                                            {money(preview.money.total)}
                                        </span>
                                    </p>
                                </>
                            )}
                        </div>
                        <p>
                            <span className="font-semibold">
                                {t('automation.voice_close.payment')}:{' '}
                            </span>
                            {t(
                                `automation.voice_close.payments.${preview.draft.payment}`,
                            )}
                        </p>
                        <p>
                            <span className="font-semibold">
                                {t('automation.voice_close.status')}:{' '}
                            </span>
                            {t(
                                `automation.voice_close.statuses.${preview.draft.status}`,
                            )}
                            {preview.draft.status === 'need_to_order_parts' &&
                                preview.draft.part_name &&
                                ` — ${t('automation.voice_close.part', { part: preview.draft.part_name })}`}
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                size="lg"
                                onClick={() => setPreview(null)}
                            >
                                <ArrowLeft /> {t('automation.voice_close.back')}
                            </Button>
                            <Button
                                size="lg"
                                loading={busy}
                                onClick={confirm}
                                data-test="voice-close-confirm"
                            >
                                <Check /> {t('automation.voice_close.confirm')}
                            </Button>
                        </div>
                    </div>
                )}
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
            </SheetContent>
        </Sheet>
    );
}
