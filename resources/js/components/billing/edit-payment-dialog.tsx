import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { fromMinor } from '@/components/billing/money';
import type { PaymentData } from '@/components/billing/types';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { update } from '@/routes/payments';
import type { Option } from '@/types';

/**
 * The pencil on a payment (owner's task of November 25): amount, method, date, reference and note can be corrected at
 * any time. A provider's payment (Square) keeps the amount and method it came with.
 */
export function EditPaymentDialog({
    payment,
    methods,
    onOpenChange,
}: {
    payment: PaymentData | null;
    methods: Option[];
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const [form, setForm] = useState({
        amount: '',
        method: '',
        received_on: '',
        reference: '',
        note: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!payment) return;
        setErrors({});
        setForm({
            amount: String(fromMinor(payment.amount, payment.currency)),
            method: payment.method,
            received_on: payment.received_at
                ? time.ymd(payment.received_at)
                : '',
            reference: payment.reference ?? '',
            note: payment.note ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payment?.id]);

    const fromProvider = payment?.provider != null;
    const allMethods =
        payment && !methods.some((m) => m.value === payment.method)
            ? [
                  ...methods,
                  { value: payment.method, label: payment.method_label },
              ]
            : methods;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!payment) return;
        setBusy(true);
        const data = fromProvider
            ? { received_on: form.received_on, note: form.note }
            : form;
        router.patch(update(payment.id).url, data, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (e) => setErrors(e),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog open={payment !== null} onOpenChange={onOpenChange}>
            <DialogContent data-test="edit-payment-dialog">
                <DialogHeader>
                    <DialogTitle>{t('payments.edit')}</DialogTitle>
                    {fromProvider && (
                        <DialogDescription>
                            {t('payments.from_provider')}
                        </DialogDescription>
                    )}
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <FormField
                        id="edit-payment-amount"
                        label={t('payments.fields.amount')}
                        error={errors.amount}
                    >
                        <Input
                            id="edit-payment-amount"
                            inputMode="decimal"
                            value={form.amount}
                            disabled={fromProvider}
                            onChange={(e) =>
                                setForm({ ...form, amount: e.target.value })
                            }
                        />
                    </FormField>
                    <FormField
                        id="edit-payment-method"
                        label={t('payments.fields.method')}
                        error={errors.method}
                    >
                        <NativeSelect
                            id="edit-payment-method"
                            value={form.method}
                            disabled={fromProvider}
                            onChange={(e) =>
                                setForm({ ...form, method: e.target.value })
                            }
                        >
                            {allMethods.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="edit-payment-date"
                        label={t('payments.fields.received_on')}
                        error={errors.received_on}
                    >
                        <Input
                            id="edit-payment-date"
                            type="date"
                            value={form.received_on}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    received_on: e.target.value,
                                })
                            }
                        />
                    </FormField>
                    {!fromProvider && (
                        <FormField
                            id="edit-payment-reference"
                            label={t('payments.fields.reference')}
                            error={errors.reference}
                        >
                            <Input
                                id="edit-payment-reference"
                                value={form.reference}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        reference: e.target.value,
                                    })
                                }
                            />
                        </FormField>
                    )}
                    <FormField
                        id="edit-payment-note"
                        label={t('payments.fields.note')}
                        error={errors.note}
                    >
                        <Textarea
                            id="edit-payment-note"
                            value={form.note}
                            onChange={(e) =>
                                setForm({ ...form, note: e.target.value })
                            }
                        />
                    </FormField>
                    <Button type="submit" className="w-full" loading={busy}>
                        <Check /> {t('common.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
