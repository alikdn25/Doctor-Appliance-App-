import { useForm } from '@inertiajs/react';
import { CheckCircle2, PackageSearch } from 'lucide-react';
import { useEffect } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { finish } from '@/routes/visits';

/**
 * Finishing a visit: the job is either completed or waits for parts.
 */
export function FinishDialog({
    open,
    onOpenChange,
    visitId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    visitId: number;
}) {
    const t = useTrans();
    const form = useForm({ outcome: '', note: '' });

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const send = (outcome: 'completed' | 'waiting_for_parts') => {
        form.transform((data) => ({ ...data, outcome }));
        form.post(finish(visitId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('jobs.actions.finish_title')}</DialogTitle>
                    <DialogDescription>
                        {t('jobs.actions.finish_hint')}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <FormField
                        id="finish-note"
                        label={t('jobs.status_note')}
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
                    <div className="grid gap-2">
                        <Button
                            size="lg"
                            className="h-12"
                            disabled={form.processing}
                            onClick={() => send('completed')}
                        >
                            <CheckCircle2 /> {t('jobs.actions.complete')}
                        </Button>
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
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
