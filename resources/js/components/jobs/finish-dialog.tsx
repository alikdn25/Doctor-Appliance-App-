import { useForm } from '@inertiajs/react';
import {
    Ban,
    CheckCircle2,
    HandCoins,
    PackageSearch,
    ShieldCheck,
    ThumbsDown,
} from 'lucide-react';
import { useMoney } from '@/components/billing/money';
import { useEffect } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { close } from '@/routes/jobs';
import { finish } from '@/routes/visits';

/** Reasons per outcome, from the company's lists. */
export type ClosureReasons = Record<
    'customer_declined' | 'unable_to_repair' | 'cancelled' | 'no_charge',
    string[]
>;

/** A warranty callback: what can be refunded on the original job. */
export type CallbackInfo = {
    number: number;
    refundable: number;
    currency: string;
} | null;

type NoRepair = 'customer_declined' | 'unable_to_repair' | 'no_charge';

/**
 * Finishing a visit on site, or closing a job from the job page: repaired, waiting for parts (visits only), or
 * closed without a repair (customer declined / unable to repair) with a reason, a comment and, optionally, an
 * invoice for the diagnosis only.
 */
export function FinishDialog({
    open,
    onOpenChange,
    visitId = null,
    jobId,
    reasons,
    callback = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Finishing this visit; null = closing the job from its page. */
    visitId?: number | null;
    jobId: number;
    reasons: ClosureReasons;
    callback?: CallbackInfo;
}) {
    const t = useTrans();
    const money = useMoney(callback?.currency);
    const form = useForm({
        outcome: '',
        note: '',
        reason: '',
        invoice_diagnosis: !callback,
        refund: 'none',
        refund_amount: '',
        refund_reason: '',
    });
    const noRepair = [
        'customer_declined',
        'unable_to_repair',
        'no_charge',
    ].includes(form.data.outcome);
    const canRefund =
        callback !== null &&
        callback.refundable > 0 &&
        ['customer_declined', 'unable_to_repair'].includes(form.data.outcome);

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const url = visitId !== null ? finish(visitId).url : close(jobId).url;

    const send = (outcome: string) => {
        form.transform((data) => ({ ...data, outcome }));
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const choose = (outcome: NoRepair) =>
        form.setData({ ...form.data, outcome, reason: '', refund: 'none' });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[95svh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {visitId !== null
                            ? t('jobs.actions.finish_title')
                            : t('jobs.close.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {visitId !== null
                            ? t('jobs.actions.finish_hint')
                            : t('jobs.close.hint')}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    {!noRepair && (
                        <div className="grid gap-2">
                            {callback ? (
                                <Button
                                    size="lg"
                                    className="h-12"
                                    disabled={form.processing}
                                    onClick={() => send('fixed_under_warranty')}
                                >
                                    <ShieldCheck />{' '}
                                    {t('jobs.outcomes.fixed_under_warranty')}
                                </Button>
                            ) : (
                                <Button
                                    size="lg"
                                    className="h-12"
                                    disabled={form.processing}
                                    onClick={() =>
                                        send(
                                            visitId !== null
                                                ? 'completed'
                                                : 'repaired',
                                        )
                                    }
                                >
                                    <CheckCircle2 />{' '}
                                    {t('jobs.actions.complete')}
                                </Button>
                            )}
                            {visitId !== null && (
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="h-12"
                                    disabled={form.processing}
                                    onClick={() => send('waiting_for_parts')}
                                >
                                    <PackageSearch />{' '}
                                    {t('jobs.actions.waiting_for_parts')}
                                </Button>
                            )}
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="h-12 whitespace-normal"
                                    onClick={() => choose('customer_declined')}
                                >
                                    <ThumbsDown />{' '}
                                    {t('jobs.outcomes.customer_declined')}
                                </Button>
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="h-12 whitespace-normal"
                                    onClick={() => choose('unable_to_repair')}
                                >
                                    <Ban />{' '}
                                    {t('jobs.outcomes.unable_to_repair')}
                                </Button>
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="col-span-2 h-12"
                                    onClick={() => choose('no_charge')}
                                >
                                    <HandCoins /> {t('jobs.outcomes.no_charge')}
                                </Button>
                            </div>
                        </div>
                    )}

                    {noRepair && (
                        <>
                            <p className="text-sm font-medium">
                                {t(`jobs.outcomes.${form.data.outcome}`)}
                            </p>
                            <FormField
                                id="close-reason"
                                label={t('jobs.close.reason')}
                                error={form.errors.reason}
                            >
                                <NativeSelect
                                    id="close-reason"
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                >
                                    <option value="">
                                        {t('jobs.close.pick_reason')}
                                    </option>
                                    {reasons[form.data.outcome as NoRepair].map(
                                        (r) => (
                                            <option key={r} value={r}>
                                                {r}
                                            </option>
                                        ),
                                    )}
                                </NativeSelect>
                            </FormField>
                            {canRefund && callback && (
                                <fieldset className="space-y-2 rounded-md border p-3">
                                    <legend className="px-1 text-sm font-medium">
                                        {t('jobs.callback.refund')}
                                    </legend>
                                    <p className="text-xs text-muted-foreground">
                                        {t('jobs.callback.refund_hint', {
                                            number: callback.number,
                                        })}
                                    </p>
                                    <NativeSelect
                                        aria-label={t('jobs.callback.refund')}
                                        value={form.data.refund}
                                        onChange={(e) =>
                                            form.setData(
                                                'refund',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="none">
                                            {t('payments.refunds.none')}
                                        </option>
                                        <option value="full">
                                            {t('payments.refunds.full', {
                                                amount: money(
                                                    callback.refundable,
                                                ),
                                            })}
                                        </option>
                                        <option value="partial">
                                            {t('payments.refunds.partial')}
                                        </option>
                                    </NativeSelect>
                                    {form.data.refund === 'partial' && (
                                        <Input
                                            aria-label={t(
                                                'payments.refunds.amount',
                                            )}
                                            placeholder={t(
                                                'payments.refunds.amount',
                                            )}
                                            inputMode="decimal"
                                            value={form.data.refund_amount}
                                            onChange={(e) =>
                                                form.setData(
                                                    'refund_amount',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    )}
                                    <InputError
                                        message={
                                            (
                                                form.errors as Record<
                                                    string,
                                                    string
                                                >
                                            ).refund_amount
                                        }
                                    />
                                    {form.data.refund !== 'none' && (
                                        <FormField
                                            id="refund-reason"
                                            label={t(
                                                'jobs.callback.refund_reason',
                                            )}
                                            error={
                                                (
                                                    form.errors as Record<
                                                        string,
                                                        string
                                                    >
                                                ).refund_reason
                                            }
                                        >
                                            <Input
                                                id="refund-reason"
                                                value={form.data.refund_reason}
                                                maxLength={500}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'refund_reason',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </FormField>
                                    )}
                                </fieldset>
                            )}
                            {form.data.outcome !== 'no_charge' && (
                                <label className="flex min-h-10 items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.invoice_diagnosis}
                                        onCheckedChange={(c) =>
                                            form.setData(
                                                'invoice_diagnosis',
                                                c === true,
                                            )
                                        }
                                    />
                                    {t('jobs.close.invoice_diagnosis')}
                                </label>
                            )}
                        </>
                    )}

                    <FormField
                        id="finish-note"
                        label={
                            noRepair
                                ? t('jobs.close.comment')
                                : t('jobs.status_note')
                        }
                        error={form.errors.note}
                    >
                        <Textarea
                            id="finish-note"
                            rows={2}
                            maxLength={500}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </FormField>
                    <InputError
                        message={
                            form.errors.outcome ??
                            (form.errors as Record<string, string>).status
                        }
                    />

                    {noRepair && (
                        <div className="grid grid-cols-[auto_1fr] gap-2">
                            <Button
                                variant="ghost"
                                className="h-12"
                                onClick={() =>
                                    form.setData({
                                        ...form.data,
                                        outcome: '',
                                        reason: '',
                                    })
                                }
                            >
                                {t('common.back')}
                            </Button>
                            <Button
                                size="lg"
                                className="h-12"
                                disabled={
                                    form.processing ||
                                    form.data.reason === '' ||
                                    (form.data.refund !== 'none' &&
                                        form.data.refund_reason.trim() === '')
                                }
                                onClick={() => send(form.data.outcome)}
                            >
                                {t('jobs.close.confirm')}
                            </Button>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
