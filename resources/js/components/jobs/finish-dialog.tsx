import { useForm } from '@inertiajs/react';
import { Ban, CheckCircle2, PackageSearch, ThumbsDown } from 'lucide-react';
import { useEffect } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    'customer_declined' | 'unable_to_repair' | 'cancelled',
    string[]
>;

type NoRepair = 'customer_declined' | 'unable_to_repair';

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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Finishing this visit; null = closing the job from its page. */
    visitId?: number | null;
    jobId: number;
    reasons: ClosureReasons;
}) {
    const t = useTrans();
    const form = useForm({
        outcome: '',
        note: '',
        reason: '',
        invoice_diagnosis: true,
    });
    const noRepair =
        form.data.outcome === 'customer_declined' ||
        form.data.outcome === 'unable_to_repair';

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
        form.setData({ ...form.data, outcome, reason: '' });

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
                                <CheckCircle2 /> {t('jobs.actions.complete')}
                            </Button>
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
                                    form.processing || form.data.reason === ''
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
