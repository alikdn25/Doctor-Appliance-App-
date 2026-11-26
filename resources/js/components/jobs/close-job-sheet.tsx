import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    Banknote,
    Check,
    CreditCard,
    Landmark,
    Plus,
    Send,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ReceiptReviewStep } from '@/components/payments/receipt-review-step';
import type {
    ReviewStepSetup,
    ReviewStepValues,
} from '@/components/payments/receipt-review-step';
import { TakePayment } from '@/components/payments/take-payment';
import type { ChargeScreen } from '@/components/payments/take-payment';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

export type CloseSheetSetup = ChargeScreen &
    ReviewStepSetup & {
        invoice: {
            id: number;
            number: string;
            total: string;
            url: string;
        } | null;
        can_take_card: boolean;
        close_url: string;
        invoice_url: string;
        new_invoice_url: string;
    };

type Line = { description: string; amount: string; tax_rate_ids: number[] };
type Method = 'cash' | 'bank_transfer' | 'card_reader' | 'no_charge';
type CardInvoice = {
    invoice_id: number;
    balance_input: string;
    total: string;
    url: string;
};

const newToken = () =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

async function postJson<T>(url: string, body: unknown): Promise<T> {
    const response = await sendJson(url, 'POST', body);
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        const first = data?.errors
            ? (Object.values(data.errors)[0] as string[])[0]
            : null;
        throw new Error(first ?? data?.message ?? response.statusText);
    }

    return data as T;
}

/**
 * The Close job window (owner's task of November 26), opened by "Complete" and by "⋯ → Close job": the open invoice's
 * total, or lines to type (description + amount without tax, a tick per company tax, "+" adds a line; optional), an
 * optional note with the microphone, and big one-tap buttons: Paid — Cash, Paid — E-transfer, Take card payment (the
 * Square reader, then back here), Send invoice (as before: the invoice goes, the job awaits payment) and No charge.
 * Then "Receipt & review" and Done: the job is closed and Today opens; the emails go afterwards.
 */
export function CloseJobSheet({
    open,
    onOpenChange,
    setup,
    onOtherOutcome,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    setup: CloseSheetSetup;
    onOtherOutcome?: () => void;
}) {
    const t = useTrans();
    const newLine = (): Line => ({
        description: '',
        amount: '',
        tax_rate_ids: [...setup.default_tax_ids],
    });
    const [step, setStep] = useState<'choose' | 'card' | 'review'>('choose');
    const [lines, setLines] = useState<Line[]>(() => [newLine()]);
    const [note, setNote] = useState('');
    const [method, setMethod] = useState<Method | null>(null);
    const [paymentId, setPaymentId] = useState<number | null>(null);
    const [card, setCard] = useState<CardInvoice | null>(null);
    const [total, setTotal] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // One id per opening: Done sent twice is applied once on the server.
    const [token, setToken] = useState(newToken);

    useEffect(() => {
        if (open) {
            setStep('choose');
            setMethod(null);
            setPaymentId(null);
            setCard(null);
            setError(null);
            setToken(newToken());
        }
    }, [open]);

    const filled = lines.filter(
        (l) => l.description.trim() !== '' || l.amount.trim() !== '',
    );
    const hasAmount = filled.some((l) => Number(l.amount) > 0);
    const body = useMemo(
        () => ({ lines: filled, amounts_include_tax: false }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [JSON.stringify(filled)],
    );

    // Taxes and the total come from the server (the company's rates), shortly after typing stops.
    useEffect(() => {
        if (setup.invoice || !hasAmount) {
            setTotal(null);

            return;
        }
        const timer = window.setTimeout(() => {
            sendJson(setup.urls.preview, 'POST', body)
                .then((r) => (r.ok ? r.json() : null))
                .then((data: { total: string } | null) =>
                    setTotal(data?.total ?? null),
                )
                .catch(() => setTotal(null));
        }, 250);

        return () => window.clearTimeout(timer);
    }, [body, hasAmount, setup.invoice, setup.urls.preview]);

    const setLine = (index: number, line: Partial<Line>) =>
        setLines(lines.map((l, i) => (i === index ? { ...l, ...line } : l)));
    const toggleTax = (index: number, id: number) => {
        const ids = lines[index].tax_rate_ids;
        setLine(index, {
            tax_rate_ids: ids.includes(id)
                ? ids.filter((x) => x !== id)
                : [...ids, id],
        });
    };

    const paid = (m: Method) => {
        setMethod(m);
        setStep('review');
    };

    // Card and Send invoice need an invoice: the open one, else one made from the lines.
    const invoiceForLines = async (): Promise<CardInvoice | null> => {
        setBusy(true);
        setError(null);
        try {
            return await postJson<CardInvoice>(setup.invoice_url, body);
        } catch (e) {
            setError((e as Error).message);

            return null;
        } finally {
            setBusy(false);
        }
    };

    const takeCard = async () => {
        if (!setup.invoice && !hasAmount) {
            setError(t('jobs.close_out.card_needs_amount'));

            return;
        }
        const invoice = await invoiceForLines();
        if (invoice) {
            setCard(invoice);
            setStep('card');
        }
    };

    const sendInvoice = async () => {
        if (setup.invoice) {
            router.visit(`${setup.invoice.url}?send=1`);

            return;
        }
        if (!hasAmount) {
            router.visit(setup.new_invoice_url);

            return;
        }
        const invoice = await invoiceForLines();
        if (invoice) router.visit(`${invoice.url}?send=1`);
    };

    const done = (values: ReviewStepValues) => {
        setBusy(true);
        router.post(
            setup.close_url,
            {
                token,
                method,
                payment_id: paymentId,
                note,
                ...body,
                ...values,
            },
            {
                onSuccess: () => onOpenChange(false),
                onError: (errors) => {
                    setError(Object.values(errors)[0] as string);
                    setStep('choose');
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const amountShown = setup.invoice?.total ?? total;
    const methodLabel =
        method === 'cash'
            ? t('charges.take.cash')
            : method === 'bank_transfer'
              ? setup.methods.e_transfer_label
              : t('charges.take.card');
    const summary =
        method === 'no_charge'
            ? t('jobs.close_out.closed_no_charge')
            : method === 'card_reader'
              ? t('jobs.close_out.paid_line', {
                    amount: card?.total ?? '',
                    method: methodLabel,
                })
              : amountShown
                ? t('jobs.close_out.paid_line', {
                      amount: amountShown,
                      method: methodLabel,
                  })
                : t('jobs.close_out.closed_no_amount');

    const big = 'min-h-14 w-full px-2 text-base font-semibold';

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[92dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="close-job-sheet"
                // No field takes the focus on opening: the keyboard stays down and the buttons stay in view.
                onOpenAutoFocus={(e) => e.preventDefault()}
            >
                <SheetHeader className="flex-row items-center gap-2 p-0 text-left">
                    {step !== 'choose' && (
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={t('jobs.close_out.back')}
                            onClick={() => setStep('choose')}
                            disabled={busy || step === 'review'}
                            className={step === 'review' ? 'hidden' : ''}
                            data-test="close-back"
                        >
                            <ArrowLeft />
                        </Button>
                    )}
                    <SheetTitle className="text-lg">
                        {step === 'review'
                            ? t('jobs.close_out.step_title')
                            : step === 'card'
                              ? t('jobs.close_out.take_card')
                              : t('jobs.close_out.title')}
                    </SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('jobs.close_out.title')}
                    </SheetDescription>
                </SheetHeader>

                {error && (
                    <p
                        role="alert"
                        className="rounded-2xl border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                        data-test="close-error"
                    >
                        {error}
                    </p>
                )}

                {step === 'review' && (
                    <ReceiptReviewStep
                        setup={setup}
                        showReceipt={
                            method !== 'no_charge' && !!(amountShown || card)
                        }
                        summary={summary}
                        busy={busy}
                        onDone={done}
                    />
                )}

                {step === 'card' && card && (
                    <TakePayment
                        screen={setup}
                        amount={card.balance_input}
                        amountLabel={card.total}
                        editable={false}
                        cardOnly
                        payload={(m) => ({
                            method: m,
                            invoice_id: card.invoice_id,
                        })}
                        onPaid={(result) => {
                            setPaymentId(result.payment_id);
                            paid('card_reader');
                        }}
                    />
                )}

                {step === 'choose' && (
                    <>
                        {setup.invoice ? (
                            <div
                                className="da-card flex items-baseline justify-between gap-3 rounded-[18px] p-4"
                                data-test="close-invoice-total"
                            >
                                <span className="text-sm text-muted-foreground">
                                    {t('jobs.close_out.invoice_total', {
                                        number: setup.invoice.number,
                                    })}
                                </span>
                                <span className="text-3xl font-bold">
                                    {setup.invoice.total}
                                </span>
                            </div>
                        ) : (
                            <div
                                className="da-card grid gap-2 rounded-[18px] p-3"
                                data-test="close-lines"
                            >
                                <p className="text-sm font-medium">
                                    {t('jobs.close_out.amount_optional')}
                                </p>
                                {lines.map((line, index) => (
                                    <div
                                        key={index}
                                        className="grid gap-2"
                                        data-test="close-line"
                                    >
                                        <div className="flex items-center gap-2">
                                            <Input
                                                value={line.description}
                                                placeholder={t(
                                                    'jobs.close_out.description',
                                                )}
                                                aria-label={t(
                                                    'jobs.close_out.description',
                                                )}
                                                maxLength={500}
                                                className="min-w-0 flex-1"
                                                onChange={(e) =>
                                                    setLine(index, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                                data-test="close-line-description"
                                            />
                                            <Input
                                                type="text"
                                                inputMode="decimal"
                                                value={line.amount}
                                                placeholder="0.00"
                                                aria-label={t(
                                                    'jobs.close_out.amount',
                                                )}
                                                className="w-24 shrink-0 text-right text-lg font-bold"
                                                onChange={(e) =>
                                                    setLine(index, {
                                                        amount: e.target.value.replace(
                                                            /[^0-9.]/g,
                                                            '',
                                                        ),
                                                    })
                                                }
                                                data-test="close-line-amount"
                                            />
                                            {lines.length > 1 && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="shrink-0 text-danger"
                                                    aria-label={t(
                                                        'jobs.close_out.remove_line',
                                                    )}
                                                    onClick={() =>
                                                        setLines(
                                                            lines.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </div>
                                        {setup.taxes.length > 0 && (
                                            <div className="flex flex-wrap gap-2">
                                                {setup.taxes.map((tax) => {
                                                    const on =
                                                        line.tax_rate_ids.includes(
                                                            tax.id,
                                                        );

                                                    return (
                                                        <button
                                                            key={tax.id}
                                                            type="button"
                                                            role="checkbox"
                                                            aria-checked={on}
                                                            onClick={() =>
                                                                toggleTax(
                                                                    index,
                                                                    tax.id,
                                                                )
                                                            }
                                                            className={cn(
                                                                'da-chip da-press inline-flex min-h-10 items-center gap-2 rounded-xl px-3 text-sm font-semibold',
                                                                on &&
                                                                    'text-primary ring-2 ring-primary',
                                                            )}
                                                            data-test={`close-tax-${tax.id}`}
                                                        >
                                                            <span
                                                                className={cn(
                                                                    'flex size-5 shrink-0 items-center justify-center rounded-md border-2',
                                                                    on
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-transparent',
                                                                )}
                                                            >
                                                                {on && (
                                                                    <Check className="size-4" />
                                                                )}
                                                            </span>
                                                            {tax.name}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                <div className="flex items-center justify-between gap-2">
                                    <Button
                                        variant="tertiary"
                                        size="sm"
                                        onClick={() =>
                                            setLines([...lines, newLine()])
                                        }
                                        data-test="close-add-line"
                                    >
                                        <Plus /> {t('jobs.close_out.add_line')}
                                    </Button>
                                    <span
                                        className="text-xl font-bold"
                                        data-test="close-total"
                                    >
                                        {total
                                            ? `${t('jobs.close_out.total')} ${total}`
                                            : ''}
                                    </span>
                                </div>
                            </div>
                        )}

                        <label className="grid gap-1 text-sm">
                            <span className="sr-only">
                                {t('jobs.close_out.note')}
                            </span>
                            <Textarea
                                rows={2}
                                maxLength={5000}
                                value={note}
                                placeholder={t('jobs.close_out.note')}
                                onChange={(e) => setNote(e.target.value)}
                                data-test="close-note"
                            />
                        </label>

                        <div className="grid grid-cols-2 gap-2">
                            {setup.methods.cash && (
                                <Button
                                    variant="outline"
                                    className={big}
                                    disabled={busy}
                                    onClick={() => paid('cash')}
                                    data-test="close-cash"
                                >
                                    <Banknote /> {t('jobs.close_out.paid_cash')}
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                className={cn(
                                    big,
                                    !setup.methods.cash && 'col-span-2',
                                )}
                                disabled={busy}
                                onClick={() => paid('bank_transfer')}
                                data-test="close-transfer"
                            >
                                <Landmark />
                                {t('jobs.close_out.paid_transfer', {
                                    method: setup.methods.e_transfer_label,
                                })}
                            </Button>
                            {setup.can_take_card && (
                                <Button
                                    className={cn(
                                        big,
                                        'da-fab-glow col-span-2',
                                    )}
                                    loading={busy}
                                    disabled={busy}
                                    onClick={() => void takeCard()}
                                    data-test="close-card"
                                >
                                    <CreditCard />{' '}
                                    {t('jobs.close_out.take_card')}
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                className={big}
                                disabled={busy}
                                onClick={() => void sendInvoice()}
                                data-test="close-send-invoice"
                            >
                                <Send /> {t('jobs.close_out.send_invoice')}
                            </Button>
                            <Button
                                variant="outline"
                                className={big}
                                disabled={busy}
                                onClick={() => paid('no_charge')}
                                data-test="close-no-charge"
                            >
                                <Ban /> {t('jobs.close_out.no_charge')}
                            </Button>
                        </div>

                        {onOtherOutcome && (
                            <button
                                type="button"
                                className="min-h-10 text-center text-sm text-link"
                                onClick={onOtherOutcome}
                                data-test="close-other-outcome"
                            >
                                {t('jobs.close_out.other_outcome')}
                            </button>
                        )}
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
