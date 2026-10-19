import { router } from '@inertiajs/react';
import { CheckCircle2, PenLine, Type, XCircle } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
import { computeTotals, currencyDecimals, fromMinor, toNumber } from './money';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { SignaturePad } from '@/components/signature-pad';
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
import {
    approve as approveRoute,
    decline as declineRoute,
} from '@/routes/documents/public';

/** What the customer can do with an estimate, and the numbers to recalculate it live (from the server). */
export type EstimateActions = {
    /** A replaced version: the newest one's page, once it was sent. */
    latest_url: string | null;
    online_payments: boolean;
    can_approve: boolean;
    can_decline: boolean;
    can_pay_deposit: boolean;
    customer_name: string | null;
    calc: {
        currency: string;
        locale: string;
        discount_type: '' | 'amount' | 'percent' | null;
        discount_value: string;
        prices_include_tax: boolean;
        taxes: {
            tax_rate_id: number | null;
            name: string;
            rate: string;
            compound: boolean;
        }[];
        items: {
            id: number;
            quantity: string;
            unit_price: number;
            taxable: boolean;
            tax_rate_ids: number[] | null;
            optional: boolean;
            selected: boolean;
        }[];
        deposit_type: 'percent' | 'amount' | null;
        deposit_value: string;
    };
};

/** The deposit for a total, as App\Models\Estimate::depositFor() works it out. */
export function depositFor(
    total: number,
    calc: Pick<
        EstimateActions['calc'],
        'currency' | 'deposit_type' | 'deposit_value'
    >,
) {
    const value = toNumber(calc.deposit_value);

    if (total <= 0 || value <= 0 || !calc.deposit_type) {
        return 0;
    }

    const amount =
        calc.deposit_type === 'percent'
            ? Math.round((total * Math.min(value, 100)) / 100)
            : Math.round(value * 10 ** currencyDecimals(calc.currency));

    return Math.min(amount, total);
}

/** Live totals for the optional lines the customer has ticked. */
export function estimateTotals(
    calc: EstimateActions['calc'],
    selected: number[],
) {
    const totals = computeTotals({
        items: calc.items.map((item) => ({
            quantity: item.quantity,
            unit_price: fromMinor(item.unit_price, calc.currency),
            taxable: item.taxable,
            tax_rate_ids: item.tax_rate_ids,
            included: !item.optional || selected.includes(item.id),
        })),
        discount_type: calc.discount_type ?? '',
        discount_value: calc.discount_value,
        taxes: calc.taxes,
        currency: calc.currency,
        prices_include_tax: calc.prices_include_tax,
    });

    return { ...totals, deposit: depositFor(totals.total, calc) };
}

/**
 * Approve (sign with a finger or type the name) and decline dialogs of the customer's online estimate.
 */
export function ApproveDialog({
    open,
    onOpenChange,
    token,
    actions,
    selected,
    total,
    deposit,
    color,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    token: string;
    actions: EstimateActions;
    selected: number[];
    total: string;
    deposit: string | null;
    color: string;
}) {
    const t = useTrans();
    const [mode, setMode] = useState<'drawn' | 'typed'>('drawn');
    const [name, setName] = useState(actions.customer_name ?? '');
    const [empty, setEmpty] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const canvas = useRef<HTMLCanvasElement | null>(null);

    const clear = () => {
        const c = canvas.current;
        c?.getContext('2d')?.clearRect(0, 0, c.width, c.height);
        setEmpty(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (mode === 'drawn' && (empty || !canvas.current)) {
            setErrors({ signature: t('estimates.online.errors.signature') });

            return;
        }

        router.post(
            approveRoute(token).url,
            {
                signer_name: name.trim(),
                signature_type: mode,
                signature:
                    mode === 'drawn'
                        ? canvas.current?.toDataURL('image/png')
                        : null,
                selected_items: selected,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errs) => setErrors(errs),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[95svh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('estimates.online.approve_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('estimates.online.approve_intro', { total })}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <FormField
                        id="signer-name"
                        label={t('estimates.online.signer_name')}
                        error={errors.signer_name}
                    >
                        <Input
                            id="signer-name"
                            value={name}
                            maxLength={100}
                            autoComplete="name"
                            required
                            onChange={(e) => setName(e.target.value)}
                        />
                    </FormField>

                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant={mode === 'drawn' ? 'default' : 'outline'}
                            className="h-11"
                            onClick={() => setMode('drawn')}
                        >
                            <PenLine /> {t('estimates.online.sign_draw')}
                        </Button>
                        <Button
                            type="button"
                            variant={mode === 'typed' ? 'default' : 'outline'}
                            className="h-11"
                            onClick={() => setMode('typed')}
                        >
                            <Type /> {t('estimates.online.sign_type')}
                        </Button>
                    </div>

                    {mode === 'drawn' ? (
                        <div className="space-y-2">
                            <p className="text-xs text-muted-foreground">
                                {t('estimates.online.draw_hint')}
                            </p>
                            {open && (
                                <SignaturePad
                                    canvasRef={canvas}
                                    onChange={(isEmpty) => {
                                        setEmpty(isEmpty);
                                        setErrors({});
                                    }}
                                />
                            )}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={clear}
                            >
                                {t('estimates.online.clear')}
                            </Button>
                        </div>
                    ) : (
                        <p
                            className="flex h-24 items-center justify-center rounded-md border bg-white px-3 font-serif text-3xl text-gray-900 italic"
                            aria-label={t('estimates.online.signature')}
                        >
                            {name.trim() || '—'}
                        </p>
                    )}
                    <InputError message={errors.signature} />
                    <InputError
                        message={
                            errors.estimate ??
                            errors.selected_items ??
                            errors.signature_type
                        }
                    />

                    <p className="text-xs text-muted-foreground">
                        {t('estimates.online.agree')}
                    </p>
                    <Button
                        type="submit"
                        className="h-12 w-full text-base"
                        style={{ backgroundColor: color }}
                        disabled={processing || name.trim() === ''}
                    >
                        <CheckCircle2 />
                        {deposit && actions.online_payments
                            ? t('estimates.online.approve_and_pay', {
                                  amount: deposit,
                              })
                            : t('estimates.online.confirm_approve')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function DeclineDialog({
    open,
    onOpenChange,
    token,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    token: string;
}) {
    const t = useTrans();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            declineRoute(token).url,
            { reason },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errs) => setError(Object.values(errs)[0]),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('estimates.online.decline_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('estimates.online.decline_reason_hint')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <FormField
                        id="decline-reason"
                        label={t('estimates.online.decline_reason')}
                        error={error}
                    >
                        <Textarea
                            id="decline-reason"
                            rows={3}
                            maxLength={2000}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    </FormField>
                    <Button
                        type="submit"
                        variant="outline"
                        className="h-12 w-full"
                        disabled={processing}
                    >
                        <XCircle /> {t('estimates.online.confirm_decline')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
