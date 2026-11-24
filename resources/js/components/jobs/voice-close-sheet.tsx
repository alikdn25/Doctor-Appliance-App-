import { router } from '@inertiajs/react';
import {
    ArrowDownUp,
    ArrowLeft,
    Check,
    Save,
    Send,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { currencyDecimals, useMoney } from '@/components/billing/money';
import { TaxIncludedSwitch } from '@/components/billing/tax-included-switch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MicButton } from '@/components/ui/mic-button';
import { NativeSelect } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
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
import type { Option } from '@/types';

type Payment = 'card' | 'cash' | 'e_transfer' | 'unpaid';
type Status = 'completed' | 'need_to_order_parts';

type InvoiceLine = {
    description: string;
    amount: number | string;
    kind: 'service' | 'part';
    tax_rate_ids: number[];
    check?: boolean;
};

type ExpenseLine = {
    description: string;
    amount: number | string;
    check?: boolean;
};

type Draft = {
    note: string;
    lines: InvoiceLine[];
    expenses: ExpenseLine[];
    amounts_include_tax: boolean;
    payment: Payment;
    status: Status;
    part_name: string;
    appliance_type: string | null;
    brand: string | null;
};

type Tax = { id: number; name: string; rate: string };

type Money = {
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

const PAYMENTS: Payment[] = ['unpaid', 'e_transfer', 'card', 'cash'];
const STATUSES: Status[] = ['completed', 'need_to_order_parts'];

const amountOf = (value: number | string): number => {
    const n =
        typeof value === 'number'
            ? value
            : Number.parseFloat(
                  value.replace(/[^\d.,]/g, '').replace(',', '.'),
              );

    return Number.isFinite(n) && n > 0 ? n : 0;
};

/** Lines the server can price: a positive amount (an empty name becomes "Service" there). */
const pricedLines = (lines: InvoiceLine[]) =>
    lines
        .filter((line) => amountOf(line.amount) > 0)
        .map((line) => ({
            description: line.description.trim(),
            amount: amountOf(line.amount),
            kind: line.kind,
            tax_rate_ids: line.tax_rate_ids,
        }));

/**
 * "Close by voice" (owner's tasks of November 18 and 24): the technician says what was done, the prices, what they
 * bought themselves, how the customer paid and whether a part is needed. The AI fills it in; the preview shows the
 * invoice (what the customer pays), My expenses (what was spent; never on the invoice), the payment and the status,
 * every field editable; lines the AI was unsure about carry "check". Taxes and totals come from the server (code).
 * Nothing is saved or sent until "Confirm & send" (texts the invoice with the payment link, or the receipt when paid)
 * or "Save only".
 */
export function VoiceCloseSheet({
    open,
    onOpenChange,
    jobId,
    applianceCategories = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    applianceCategories?: Option[];
}) {
    const t = useTrans();
    const [text, setText] = useState('');
    const [busy, setBusy] = useState<false | 'read' | 'send' | 'save'>(false);
    const [error, setError] = useState<string | null>(null);
    const [draft, setDraft] = useState<Draft | null>(null);
    const [totals, setTotals] = useState<Money | null>(null);
    const [taxes, setTaxes] = useState<Tax[]>([]);
    const money = useMoney(totals?.currency);
    const decimals = currencyDecimals(totals?.currency ?? 'USD');
    const recalcTimer = useRef<number | undefined>(undefined);

    useEffect(() => {
        window.clearTimeout(recalcTimer.current);
        if (open) {
            setDraft(null);
            setTotals(null);
            setError(null);
        }
    }, [open]);

    const readText = async () => {
        if (text.trim() === '' || busy) return;
        setBusy('read');
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
            setDraft({ ...body.draft, expenses: body.draft.expenses ?? [] });
            setTotals(body.money);
        } catch {
            setError(t('automation.inbox.failed'));
        } finally {
            setBusy(false);
        }
    };

    // Any change to the invoice lines or "Price includes tax": the money again from the server (code), shortly after.
    const update = (next: Draft, recalc = false) => {
        setDraft(next);
        if (!recalc) return;
        window.clearTimeout(recalcTimer.current);
        recalcTimer.current = window.setTimeout(async () => {
            try {
                const response = await sendJson(
                    previewClose(jobId).url,
                    'POST',
                    {
                        lines: pricedLines(next.lines),
                        amounts_include_tax: next.amounts_include_tax,
                    },
                );
                const body = (await response.json()) as { money?: Money };
                if (response.ok && body.money) {
                    setTotals(body.money);
                }
            } catch {
                setError(t('automation.inbox.failed'));
            }
        }, 350);
    };

    const setLine = (index: number, patch: Partial<InvoiceLine>) =>
        draft &&
        update(
            {
                ...draft,
                lines: draft.lines.map((line, i) =>
                    i === index ? { ...line, ...patch, check: false } : line,
                ),
            },
            true,
        );
    const setExpense = (index: number, patch: Partial<ExpenseLine>) =>
        draft &&
        update({
            ...draft,
            expenses: draft.expenses.map((line, i) =>
                i === index ? { ...line, ...patch, check: false } : line,
            ),
        });
    const toInvoice = (index: number) => {
        if (!draft) return;
        const line = draft.expenses[index];
        update(
            {
                ...draft,
                expenses: draft.expenses.filter((_, i) => i !== index),
                lines: [
                    ...draft.lines,
                    {
                        description: line.description,
                        amount: line.amount,
                        kind: 'part',
                        tax_rate_ids: taxes.slice(0, 1).map((x) => x.id),
                    },
                ],
            },
            true,
        );
    };
    const toExpenses = (index: number) => {
        if (!draft) return;
        const line = draft.lines[index];
        update(
            {
                ...draft,
                lines: draft.lines.filter((_, i) => i !== index),
                expenses: [
                    ...draft.expenses,
                    { description: line.description, amount: line.amount },
                ],
            },
            true,
        );
    };

    const expensesTotal = draft
        ? draft.expenses.reduce((sum, line) => sum + amountOf(line.amount), 0)
        : 0;
    const paid = draft !== null && draft.payment !== 'unpaid';

    const save = (send: boolean) => {
        if (!draft) return;
        router.post(
            voiceClose(jobId).url,
            {
                ...draft,
                lines: pricedLines(draft.lines),
                expenses: draft.expenses
                    .filter((line) => amountOf(line.amount) > 0)
                    .map((line) => ({
                        description: line.description.trim(),
                        amount: amountOf(line.amount),
                    })),
                send,
            } as never,
            {
                preserveScroll: true,
                onStart: () => setBusy(send ? 'send' : 'save'),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setText('');
                    onOpenChange(false);
                },
                onError: (errors) =>
                    setError(String(Object.values(errors)[0] ?? '')),
            },
        );
    };

    const checkBadge = (on?: boolean) =>
        on ? (
            <span
                className="shrink-0 rounded-full bg-warning/15 px-2 py-0.5 text-xs font-bold text-warning uppercase ring-1 ring-warning"
                title={t('automation.voice_close.check_hint')}
                data-test="voice-close-check"
            >
                {t('automation.voice_close.check')}
            </span>
        ) : null;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[92dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="voice-close-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{t('automation.voice_close.title')}</SheetTitle>
                    <SheetDescription>
                        {t('automation.voice_close.hint')}
                    </SheetDescription>
                </SheetHeader>

                {draft === null || totals === null ? (
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
                            loading={busy === 'read'}
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

                        {/* Note */}
                        <div className="space-y-1">
                            <label
                                htmlFor="voice-close-note"
                                className="font-semibold"
                            >
                                {t('automation.voice_close.note')}
                            </label>
                            <Textarea
                                id="voice-close-note"
                                rows={2}
                                maxLength={5000}
                                value={draft.note}
                                onChange={(e) =>
                                    update({ ...draft, note: e.target.value })
                                }
                            />
                        </div>

                        {/* Invoice: what the customer pays */}
                        <div
                            className="da-card space-y-2 p-3"
                            data-test="voice-close-invoice"
                        >
                            <p className="font-semibold">
                                {t('automation.voice_close.lines')}
                            </p>
                            {draft.lines.length === 0 && (
                                <p className="text-muted-foreground">
                                    {t('automation.voice_close.no_lines')}
                                </p>
                            )}
                            {draft.lines.map((line, i) => (
                                <div
                                    key={i}
                                    className={cn(
                                        'space-y-1.5 rounded-2xl border p-2',
                                        line.check && 'border-warning',
                                    )}
                                    data-test="voice-close-line"
                                >
                                    <div className="flex items-center gap-2">
                                        <Input
                                            value={line.description}
                                            maxLength={500}
                                            aria-label={t(
                                                'automation.voice_close.description',
                                            )}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    description: e.target.value,
                                                })
                                            }
                                            className="min-w-0 flex-1"
                                        />
                                        <Input
                                            value={String(line.amount)}
                                            inputMode="decimal"
                                            aria-label={t(
                                                'automation.voice_close.amount',
                                            )}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    amount: e.target.value,
                                                })
                                            }
                                            className="w-24 text-right tabular-nums"
                                        />
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {checkBadge(line.check)}
                                        <NativeSelect
                                            value={line.kind}
                                            aria-label={t(
                                                'automation.voice_close.kind',
                                            )}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    kind: e.target
                                                        .value as InvoiceLine['kind'],
                                                })
                                            }
                                            className="h-11 w-auto"
                                        >
                                            <option value="service">
                                                {t(
                                                    'automation.voice_close.kinds.service',
                                                )}
                                            </option>
                                            <option value="part">
                                                {t(
                                                    'automation.voice_close.kinds.part',
                                                )}
                                            </option>
                                        </NativeSelect>
                                        {taxes.map((tax) => {
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
                                                        setLine(i, {
                                                            tax_rate_ids: on
                                                                ? line.tax_rate_ids.filter(
                                                                      (x) =>
                                                                          x !==
                                                                          tax.id,
                                                                  )
                                                                : [
                                                                      ...line.tax_rate_ids,
                                                                      tax.id,
                                                                  ],
                                                        })
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
                                                    {tax.name} {tax.rate}%
                                                </button>
                                            );
                                        })}
                                        <span className="ml-auto flex gap-1">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t(
                                                    'automation.voice_close.move_to_expenses',
                                                )}
                                                title={t(
                                                    'automation.voice_close.move_to_expenses',
                                                )}
                                                onClick={() => toExpenses(i)}
                                                data-test="voice-close-to-expenses"
                                            >
                                                <ArrowDownUp />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t(
                                                    'automation.voice_close.remove_line',
                                                )}
                                                onClick={() =>
                                                    update(
                                                        {
                                                            ...draft,
                                                            lines: draft.lines.filter(
                                                                (_, x) =>
                                                                    x !== i,
                                                            ),
                                                        },
                                                        true,
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </span>
                                    </div>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    update({
                                        ...draft,
                                        lines: [
                                            ...draft.lines,
                                            {
                                                description: '',
                                                amount: '',
                                                kind: 'service',
                                                tax_rate_ids: taxes
                                                    .slice(0, 1)
                                                    .map((x) => x.id),
                                            },
                                        ],
                                    })
                                }
                                data-test="voice-close-add-line"
                            >
                                {t('automation.voice_close.add_line')}
                            </Button>
                            {draft.lines.length > 0 && (
                                <>
                                    <TaxIncludedSwitch
                                        checked={draft.amounts_include_tax}
                                        onChange={(on) =>
                                            update(
                                                {
                                                    ...draft,
                                                    amounts_include_tax: on,
                                                },
                                                true,
                                            )
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
                                            {money(totals.subtotal)}
                                        </span>
                                    </p>
                                    {totals.taxes.map((tax) => (
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
                                            {money(totals.total)}
                                        </span>
                                    </p>
                                </>
                            )}
                        </div>

                        {/* My expenses: what was spent; never on the invoice */}
                        <div
                            className="da-card space-y-2 p-3"
                            data-test="voice-close-expenses"
                        >
                            <p className="font-semibold">
                                {t('automation.voice_close.expenses')}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t('automation.voice_close.expenses_hint')}
                            </p>
                            {draft.expenses.length === 0 && (
                                <p className="text-muted-foreground">
                                    {t('automation.voice_close.no_expenses')}
                                </p>
                            )}
                            {draft.expenses.map((line, i) => (
                                <div
                                    key={i}
                                    className={cn(
                                        'flex flex-wrap items-center gap-2 rounded-2xl border p-2',
                                        line.check && 'border-warning',
                                    )}
                                    data-test="voice-close-expense"
                                >
                                    <Input
                                        value={line.description}
                                        maxLength={255}
                                        aria-label={t(
                                            'automation.voice_close.description',
                                        )}
                                        onChange={(e) =>
                                            setExpense(i, {
                                                description: e.target.value,
                                            })
                                        }
                                        className="min-w-0 flex-1 basis-32"
                                    />
                                    <Input
                                        value={String(line.amount)}
                                        inputMode="decimal"
                                        aria-label={t(
                                            'automation.voice_close.amount',
                                        )}
                                        onChange={(e) =>
                                            setExpense(i, {
                                                amount: e.target.value,
                                            })
                                        }
                                        className="w-24 text-right tabular-nums"
                                    />
                                    {checkBadge(line.check)}
                                    <span className="ml-auto flex gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t(
                                                'automation.voice_close.move_to_invoice',
                                            )}
                                            title={t(
                                                'automation.voice_close.move_to_invoice',
                                            )}
                                            onClick={() => toInvoice(i)}
                                        >
                                            <ArrowDownUp />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t(
                                                'automation.voice_close.remove_line',
                                            )}
                                            onClick={() =>
                                                update({
                                                    ...draft,
                                                    expenses:
                                                        draft.expenses.filter(
                                                            (_, x) => x !== i,
                                                        ),
                                                })
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </span>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    update({
                                        ...draft,
                                        expenses: [
                                            ...draft.expenses,
                                            { description: '', amount: '' },
                                        ],
                                    })
                                }
                            >
                                {t('automation.voice_close.add_expense')}
                            </Button>
                            {draft.expenses.length > 0 && (
                                <p
                                    className="flex justify-between gap-2 border-t pt-1 font-bold"
                                    data-test="voice-close-expenses-total"
                                >
                                    <span>
                                        {t(
                                            'automation.voice_close.expenses_total',
                                        )}
                                    </span>
                                    <span className="tabular-nums">
                                        {money(
                                            Math.round(
                                                expensesTotal * 10 ** decimals,
                                            ),
                                        )}
                                    </span>
                                </p>
                            )}
                        </div>

                        {/* Payment */}
                        <div className="da-card space-y-2 p-3">
                            <p className="font-semibold">
                                {t('automation.voice_close.payment')}
                            </p>
                            <SegmentedControl
                                options={PAYMENTS.map((value) => ({
                                    value,
                                    label: t(
                                        `automation.voice_close.payments.${value}`,
                                    ),
                                }))}
                                value={draft.payment}
                                onChange={(payment) =>
                                    update({ ...draft, payment })
                                }
                                label={t('automation.voice_close.payment')}
                                className="flex w-full flex-wrap [&>button]:flex-1"
                            />
                        </div>

                        {/* Status and appliance */}
                        <div className="da-card space-y-2 p-3">
                            <p className="font-semibold">
                                {t('automation.voice_close.status')}
                            </p>
                            <SegmentedControl
                                options={STATUSES.map((value) => ({
                                    value,
                                    label: t(
                                        `automation.voice_close.statuses.${value}`,
                                    ),
                                }))}
                                value={draft.status}
                                onChange={(status) =>
                                    update({ ...draft, status })
                                }
                                label={t('automation.voice_close.status')}
                                className="flex w-full flex-wrap [&>button]:flex-1"
                            />
                            {draft.status === 'need_to_order_parts' && (
                                <Input
                                    value={draft.part_name}
                                    maxLength={255}
                                    placeholder={t(
                                        'automation.voice_close.part_name',
                                    )}
                                    aria-label={t(
                                        'automation.voice_close.part_name',
                                    )}
                                    onChange={(e) =>
                                        update({
                                            ...draft,
                                            part_name: e.target.value,
                                        })
                                    }
                                />
                            )}
                            <div
                                className="grid grid-cols-2 gap-2"
                                data-test="voice-close-appliance"
                            >
                                <NativeSelect
                                    value={draft.appliance_type ?? ''}
                                    aria-label={t(
                                        'automation.voice_close.appliance_type',
                                    )}
                                    onChange={(e) =>
                                        update({
                                            ...draft,
                                            appliance_type:
                                                e.target.value || null,
                                        })
                                    }
                                >
                                    <option value="">
                                        {t('automation.voice_close.not_set')}
                                    </option>
                                    {applianceCategories.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <Input
                                    value={draft.brand ?? ''}
                                    maxLength={100}
                                    placeholder={t(
                                        'automation.voice_close.brand',
                                    )}
                                    aria-label={t(
                                        'automation.voice_close.brand',
                                    )}
                                    onChange={(e) =>
                                        update({
                                            ...draft,
                                            brand: e.target.value || null,
                                        })
                                    }
                                />
                            </div>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            {t(
                                paid
                                    ? 'automation.voice_close.confirm_hint_paid'
                                    : 'automation.voice_close.confirm_hint_unpaid',
                            )}
                        </p>
                        <Button
                            size="lg"
                            className="w-full"
                            loading={busy === 'send'}
                            disabled={busy !== false}
                            onClick={() => save(true)}
                            data-test="voice-close-confirm"
                        >
                            <Send /> {t('automation.voice_close.confirm')}
                        </Button>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                size="lg"
                                disabled={busy !== false}
                                onClick={() => {
                                    setDraft(null);
                                    setTotals(null);
                                }}
                            >
                                <ArrowLeft /> {t('automation.voice_close.back')}
                            </Button>
                            <Button
                                variant="outline"
                                size="lg"
                                loading={busy === 'save'}
                                disabled={busy !== false}
                                onClick={() => save(false)}
                                data-test="voice-close-save-only"
                            >
                                <Save /> {t('automation.voice_close.save_only')}
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
