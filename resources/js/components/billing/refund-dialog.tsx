import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useMemo } from 'react';
import { toMinor, useMoney } from '@/components/billing/money';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { refund as refundRoute } from '@/routes/invoices';

export type RefundSetup = {
    /** Paid and not refunded yet (minor units, taxes included). */
    left: number;
    taxes: {
        name: string;
        rate: string;
        compound: boolean;
        checked: boolean;
    }[];
    /** A payment went through Square: the refund goes back to the same card there. */
    square: boolean;
    method: 'cash' | 'e_transfer' | 'card';
    card_last4: string | null;
};

/** The taxes on top of an amount without taxes, as the server works them out (a compound tax builds on the others). */
function taxesOn(
    base: number,
    taxes: RefundSetup['taxes'],
    checked: string[],
): { name: string; rate: string; amount: number }[] {
    const lines: { name: string; rate: string; amount: number }[] = [];
    let before = 0;

    for (const tax of taxes) {
        if (!checked.includes(tax.name)) continue;
        const amount = Math.round(
            ((tax.compound ? base + before : base) * Number(tax.rate)) / 100,
        );
        lines.push({ name: tax.name, rate: tax.rate, amount });
        before += amount;
    }

    return lines;
}

/**
 * The refund window (owner's task of November 26): Full refund (all that is left, taxes in proportion) or Partial
 * refund (an amount without taxes, the company's taxes ticked as on the invoice add on top); the lines under the
 * field add up to "Total refund", never more than what is left. A card paid through Square goes back to the same
 * card; if Square says no, its reason shows with "Record as cash / e-transfer". Cash and e-transfer are recorded
 * only, by the same way unless another is picked. The reason is optional; one confirmation.
 */
export function RefundDialog({
    open,
    onOpenChange,
    invoiceId,
    currency,
    setup,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoiceId: number;
    currency: string;
    setup: RefundSetup;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const form = useForm({
        mode: 'full' as 'full' | 'partial',
        amount: '',
        taxes: [] as string[],
        method: setup.method as 'cash' | 'e_transfer' | 'card',
        record_only: false,
        reason: '',
    });

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({
            mode: 'full',
            amount: '',
            taxes: setup.taxes
                .filter((tax) => tax.checked)
                .map((tax) => tax.name),
            method: setup.method,
            record_only: false,
            reason: '',
        });
        // Reset only when the window opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const partial = form.data.mode === 'partial';
    const base = partial ? toMinor(form.data.amount || '0', currency) : 0;
    const lines = useMemo(
        () => (partial ? taxesOn(base, setup.taxes, form.data.taxes) : []),
        [partial, base, setup.taxes, form.data.taxes],
    );
    const total = partial
        ? base + lines.reduce((sum, line) => sum + line.amount, 0)
        : setup.left;
    const tooMuch = total > setup.left;
    const errors = form.errors as Record<string, string | undefined>;
    const squareRefused = Boolean(errors.provider);
    // Square refunds the card; a manual record otherwise (or after Square said no).
    const viaSquare = setup.square && !form.data.record_only;

    const send = (recordOnly: boolean) => {
        if (total <= 0 || tooMuch) return;
        if (
            !window.confirm(
                t('payments.refunds.confirm', { amount: money(total) }),
            )
        )
            return;
        form.transform((d) => ({
            mode: d.mode,
            amount: d.mode === 'partial' ? d.amount : null,
            taxes: d.taxes,
            method: setup.square && !recordOnly ? null : d.method,
            record_only: recordOnly,
            reason: d.reason.trim() === '' ? null : d.reason,
        }));
        form.post(refundRoute(invoiceId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errors) => {
                if (errors.provider) form.setData('record_only', true);
            },
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(form.data.record_only);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90svh] overflow-y-auto sm:max-w-md"
                data-test="refund-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{t('payments.refunds.title')}</DialogTitle>
                    <DialogDescription>
                        {t('payments.refunds.hint')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <SegmentedControl
                        label={t('payments.refunds.title')}
                        className="h-12"
                        value={form.data.mode}
                        onChange={(mode) =>
                            form.setData('mode', mode as 'full' | 'partial')
                        }
                        options={[
                            {
                                value: 'full',
                                label: t('payments.refunds.full'),
                            },
                            {
                                value: 'partial',
                                label: t('payments.refunds.partial'),
                            },
                        ]}
                    />

                    {partial ? (
                        <div className="space-y-3">
                            <FormField
                                id="refund-amount"
                                label={t('payments.refunds.amount_before_tax')}
                                error={tooMuch ? undefined : form.errors.amount}
                            >
                                <Input
                                    id="refund-amount"
                                    inputMode="decimal"
                                    autoFocus
                                    value={form.data.amount}
                                    onChange={(e) =>
                                        form.setData('amount', e.target.value)
                                    }
                                    data-test="refund-amount"
                                />
                            </FormField>
                            {setup.taxes.length > 0 && (
                                <div className="flex flex-wrap gap-4">
                                    {setup.taxes.map((tax) => (
                                        <label
                                            key={tax.name}
                                            className="flex min-h-11 items-center gap-2 text-sm font-medium"
                                        >
                                            <input
                                                type="checkbox"
                                                className="size-5"
                                                checked={form.data.taxes.includes(
                                                    tax.name,
                                                )}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'taxes',
                                                        e.target.checked
                                                            ? [
                                                                  ...form.data
                                                                      .taxes,
                                                                  tax.name,
                                                              ]
                                                            : form.data.taxes.filter(
                                                                  (n) =>
                                                                      n !==
                                                                      tax.name,
                                                              ),
                                                    )
                                                }
                                                data-test={`refund-tax-${tax.name}`}
                                            />
                                            {tax.name} {tax.rate}%
                                        </label>
                                    ))}
                                </div>
                            )}
                            <dl
                                className="space-y-1 text-sm"
                                data-test="refund-lines"
                            >
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        {t('payments.refunds.subtotal')}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {money(base)}
                                    </dd>
                                </div>
                                {lines.map((line) => (
                                    <div
                                        key={line.name}
                                        className="flex justify-between"
                                    >
                                        <dt className="text-muted-foreground">
                                            {line.name} {line.rate}%
                                        </dt>
                                        <dd className="tabular-nums">
                                            {money(line.amount)}
                                        </dd>
                                    </div>
                                ))}
                                <div className="flex justify-between border-t pt-1 font-semibold">
                                    <dt>{t('payments.refunds.total')}</dt>
                                    <dd
                                        className="tabular-nums"
                                        data-test="refund-total"
                                    >
                                        {money(total)}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    ) : (
                        <p className="text-sm" data-test="refund-full">
                            {t('payments.refunds.full_hint', {
                                amount: money(setup.left),
                            })}
                        </p>
                    )}
                    {tooMuch && (
                        <p
                            className="text-sm font-semibold text-danger"
                            data-test="refund-too-much"
                        >
                            {t('payments.refunds.more_than_paid', {
                                amount: money(setup.left),
                            })}
                        </p>
                    )}

                    {viaSquare ? (
                        <p className="text-sm text-muted-foreground">
                            {t('payments.refunds.to_square')}
                            {setup.card_last4
                                ? ` ${t('payments.refunds.to_card', { last4: setup.card_last4 })}`
                                : ''}
                        </p>
                    ) : (
                        <div className="space-y-1">
                            <SegmentedControl
                                label={t('payments.refunds.method')}
                                className="h-12"
                                value={form.data.method}
                                onChange={(method) =>
                                    form.setData(
                                        'method',
                                        method as
                                            | 'cash'
                                            | 'e_transfer'
                                            | 'card',
                                    )
                                }
                                options={(
                                    ['cash', 'e_transfer', 'card'] as const
                                ).map((method) => ({
                                    value: method,
                                    label: t(
                                        `payments.refunds.methods.${method}`,
                                    ),
                                }))}
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('payments.refunds.record_hint')}
                            </p>
                        </div>
                    )}

                    <FormField
                        id="refund-reason"
                        label={t('payments.refunds.reason_optional')}
                        error={form.errors.reason}
                    >
                        <Textarea
                            id="refund-reason"
                            rows={2}
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                    </FormField>

                    {squareRefused && (
                        <div
                            className="space-y-2 rounded-2xl border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:bg-red-500/10 dark:text-red-100"
                            data-test="refund-square-refused"
                        >
                            <p className="font-semibold">
                                {t('payments.refunds.square_refused')}
                            </p>
                            <p>{errors.provider}</p>
                            {/* No "Refund on reader": the Square SDK in the Android app cannot refund on the reader. */}
                            <div className="grid gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => send(true)}
                                    data-test="refund-record-cash"
                                >
                                    {t('payments.refunds.record_cash')}
                                </Button>
                            </div>
                        </div>
                    )}

                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        loading={form.processing}
                        disabled={form.processing || total <= 0 || tooMuch}
                        data-test="refund-submit"
                    >
                        {t('payments.refunds.submit', { amount: money(total) })}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
