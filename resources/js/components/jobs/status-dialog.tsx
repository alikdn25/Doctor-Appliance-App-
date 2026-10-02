import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
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
import { status as statusRoute } from '@/routes/jobs';
import type { Option } from '@/types';

/**
 * Manual status change by the office, with an optional note for the log.
 */
export function StatusDialog({
    open,
    onOpenChange,
    jobId,
    current,
    options,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    current: string;
    options: Option[];
}) {
    const t = useTrans();
    const form = useForm({ status: current, note: '' });

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData({ status: current, note: '' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, current]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(statusRoute(jobId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('jobs.change_status')}</DialogTitle>
                    <DialogDescription>
                        {t('jobs.status_note_hint')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <FormField
                        id="job-status"
                        label={t('jobs.change_status')}
                        error={form.errors.status}
                    >
                        <NativeSelect
                            id="job-status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            {options.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="job-status-note"
                        label={t('jobs.status_note')}
                        error={form.errors.note}
                    >
                        <Textarea
                            id="job-status-note"
                            rows={2}
                            maxLength={500}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </FormField>
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={
                            form.processing || form.data.status === current
                        }
                    >
                        {t('common.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
