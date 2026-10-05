import { Camera } from 'lucide-react';
import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import {
    currencySymbol,
    fromMinor,
    toMinor,
    useMoney,
} from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { store } from '@/routes/payments';
import type { Option } from '@/types';

const referenceLabel: Record<string, string> = {
    check: 'payments.fields.check_number',
    bank_transfer: 'payments.fields.transfer_reference',
    card_terminal: 'payments.fields.transaction_reference',
};

/**
 * Manual payment: pick the method with one tap, the amount defaults to the full balance.
 */
export function PaymentDialog({
    open,
    onOpenChange,
    invoiceId,
    balance,
    currency,
    methods,
    today,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoiceId: number;
    balance: number;
    currency: string;
    methods: Option[];
    today: string;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const { auth } = usePage().props;
    const symbol = currencySymbol(currency, auth.company?.locale);
    const form = useForm<{
        amount: string;
        method: string;
        reference: string;
        note: string;
        received_on: string;
        receipt: File | null;
    }>({
        amount: fromMinor(balance, currency),
        method: '',
        reference: '',
        note: '',
        received_on: today,
        receipt: null,
    });

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData({
                amount: fromMinor(balance, currency),
                method: '',
                reference: '',
                note: '',
                received_on: today,
                receipt: null,
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, balance, today]);

    const method = form.data.method;
    const amount = toMinor(form.data.amount, currency);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            amount: d.amount.replace(/[^\d.]/g, ''),
            reference: referenceLabel[d.method] ? d.reference : '',
            receipt: d.method === 'cash' ? d.receipt : null,
        }));
        form.post(store(invoiceId).url, {
            preserveScroll: true,
            forceFormData:
                form.data.method === 'cash' && form.data.receipt !== null,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {/* Anchored to the top: picking a method adds fields below without the window jumping. */}
            <DialogContent className="top-4 translate-y-0 pb-0 sm:top-[8vh] sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('payments.record')}</DialogTitle>
                    <DialogDescription>
                        {t('invoices.balance')}: {money(balance)}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div
                        role="radiogroup"
                        aria-label={t('payments.fields.method')}
                        className="grid grid-cols-2 gap-2"
                    >
                        {methods.map((m) => (
                            <button
                                key={m.value}
                                type="button"
                                role="radio"
                                aria-checked={method === m.value}
                                onClick={() => form.setData('method', m.value)}
                                className={cn(
                                    'min-h-12 rounded-md border px-3 text-sm font-medium transition-colors',
                                    method === m.value
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'hover:bg-muted',
                                )}
                            >
                                {m.label}
                            </button>
                        ))}
                    </div>
                    {form.errors.method && (
                        <p className="text-sm text-destructive">
                            {form.errors.method}
                        </p>
                    )}

                    <FormField
                        id="payment-amount"
                        label={t('payments.fields.amount')}
                        error={form.errors.amount}
                    >
                        <div className="flex gap-2">
                            <div className="relative flex-1">
                                <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    {symbol}
                                </span>
                                <Input
                                    id="payment-amount"
                                    inputMode="decimal"
                                    className="h-11 text-base"
                                    style={{
                                        paddingLeft: `${symbol.length * 0.6 + 1}rem`,
                                    }}
                                    value={form.data.amount}
                                    onChange={(e) =>
                                        form.setData('amount', e.target.value)
                                    }
                                />
                            </div>
                            {amount !== balance && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11"
                                    onClick={() =>
                                        form.setData(
                                            'amount',
                                            fromMinor(balance, currency),
                                        )
                                    }
                                >
                                    {t('payments.pay_full')}
                                </Button>
                            )}
                        </div>
                    </FormField>

                    {referenceLabel[method] && (
                        <FormField
                            id="payment-reference"
                            label={t(referenceLabel[method])}
                            hint={
                                method === 'card_terminal'
                                    ? t('payments.hints.card_terminal')
                                    : t('payments.hints.reference')
                            }
                            error={form.errors.reference}
                        >
                            <Input
                                id="payment-reference"
                                maxLength={100}
                                required={method === 'card_terminal'}
                                value={form.data.reference}
                                onChange={(e) =>
                                    form.setData('reference', e.target.value)
                                }
                            />
                        </FormField>
                    )}

                    <FormField
                        id="payment-note"
                        label={t('payments.fields.note')}
                        hint={
                            method === 'other'
                                ? t('payments.hints.other')
                                : undefined
                        }
                        error={form.errors.note}
                    >
                        <Textarea
                            id="payment-note"
                            rows={2}
                            maxLength={1000}
                            required={method === 'other'}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </FormField>

                    {method === 'cash' && (
                        <FormField
                            id="payment-receipt"
                            label={t('payments.fields.receipt_photo')}
                            error={
                                (form.errors as Record<string, string>).receipt
                            }
                        >
                            <Button
                                asChild
                                variant="outline"
                                className="cursor-pointer justify-start"
                            >
                                <label>
                                    <Camera />
                                    <span className="truncate">
                                        {form.data.receipt?.name ??
                                            t('payments.take_receipt_photo')}
                                    </span>
                                    <input
                                        id="payment-receipt"
                                        type="file"
                                        accept="image/*"
                                        capture="environment"
                                        className="sr-only"
                                        onChange={(e) =>
                                            form.setData(
                                                'receipt',
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </label>
                            </Button>
                        </FormField>
                    )}

                    <FormField
                        id="payment-date"
                        label={t('payments.fields.received_on')}
                        error={form.errors.received_on}
                    >
                        <Input
                            id="payment-date"
                            type="date"
                            max={today}
                            value={form.data.received_on}
                            onChange={(e) =>
                                form.setData('received_on', e.target.value)
                            }
                        />
                    </FormField>

                    <div className="sticky bottom-0 -mx-6 border-t bg-background px-6 py-3">
                        <Button
                            type="submit"
                            className="h-12 w-full text-base"
                            disabled={form.processing || !method || amount <= 0}
                        >
                            {t('payments.record')} · {money(amount)}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
