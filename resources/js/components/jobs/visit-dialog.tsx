import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import type { Assignable, Visit } from '@/components/jobs/types';
import { VisitFields } from '@/components/jobs/visit-fields';
import type { VisitFormValue } from '@/components/jobs/visit-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTrans } from '@/lib/i18n';
import { store, update } from '@/routes/visits';

const toForm = (visit: Visit | null, today: string): VisitFormValue => ({
    date: visit?.date ?? today,
    start_time: visit?.start_time ?? '09:00',
    end_time: visit?.end_time ?? '11:00',
    estimated_duration_minutes: String(visit?.estimated_duration_minutes ?? 60),
    assignee_ids: visit?.assignees.map((a) => a.id) ?? [],
    strict_arrival: visit?.strict_arrival ?? false,
});

/**
 * Schedule a new visit for a job or edit an existing one (office).
 */
export function VisitDialog({
    open,
    onOpenChange,
    jobId,
    visit,
    today,
    assignableUsers,
    initial,
    stayOnPage = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    visit: Visit | null;
    today: string;
    assignableUsers: Assignable[];
    /** Prefilled values for a new visit (e.g. the slot picked on the calendar). */
    initial?: Partial<VisitFormValue>;
    /** Stay on the current page after saving (the calendar). */
    stayOnPage?: boolean;
}) {
    const t = useTrans();
    const form = useForm<VisitFormValue>(toForm(visit, today));

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData({
                ...toForm(visit, today),
                ...(visit ? {} : initial),
            });
        }
        // Reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, visit]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };
        form.transform((data) => (stayOnPage ? { ...data, back: true } : data));

        if (visit) {
            form.put(update(visit.id).url, options);
        } else {
            form.post(store(jobId).url, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {visit ? t('jobs.edit_visit') : t('jobs.add_visit')}
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <VisitFields
                        value={form.data}
                        onChange={(patch) =>
                            form.setData({ ...form.data, ...patch })
                        }
                        errors={form.errors as Record<string, string>}
                        assignableUsers={assignableUsers}
                    />
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
