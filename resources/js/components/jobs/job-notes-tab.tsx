import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { CustomerNotesEditor } from '@/components/customers/customer-notes-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Section } from '@/components/ui/section';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { destroy, store } from '@/routes/jobs/notes';

export type TeamNote = {
    id: number;
    body: string;
    author: string | null;
    at: string;
    can_delete: boolean;
};

/**
 * The Notes tab (owner's task, Oct 24): "About the customer" on top (it follows the customer to every job), then
 * the team's notes on this job with who wrote them and when. Messages to the customer live on the Messages tab.
 */
export function JobNotesTab({
    jobId,
    customerId,
    customerNotes,
    canUpdateCustomer,
    canWork,
    notes,
}: {
    jobId: number;
    customerId: number;
    customerNotes: string | null;
    canUpdateCustomer: boolean;
    canWork: boolean;
    notes: TeamNote[];
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const form = useForm({ body: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.body.trim() === '') return;
        form.post(store(jobId).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const remove = (note: TeamNote) => {
        if (!confirm(t('jobs.notes_tab.confirm_delete'))) return;
        router.delete(destroy(note.id).url, { preserveScroll: true });
    };

    return (
        <div className="flex flex-col gap-5" data-test="job-tab-notes">
            {/* The editor has its own "About the customer" label; read-only gets the section title. */}
            {canUpdateCustomer ? (
                <CustomerNotesEditor
                    customerId={customerId}
                    notes={customerNotes}
                    canUpdate
                />
            ) : (
                <Section title={t('jobs.notes_tab.about_customer')}>
                    <p className="da-card p-4 text-sm whitespace-pre-wrap">
                        {customerNotes?.trim()
                            ? customerNotes
                            : t('jobs.notes_tab.about_customer_empty')}
                    </p>
                </Section>
            )}

            <Section title={t('jobs.notes_tab.job_notes')}>
                {canWork && (
                    <form onSubmit={submit} className="da-card space-y-2 p-3">
                        <Textarea
                            id="job-note"
                            aria-label={t('jobs.notes_tab.note')}
                            placeholder={t('jobs.notes_tab.placeholder')}
                            rows={2}
                            maxLength={5000}
                            className="[field-sizing:content] min-h-16"
                            value={form.data.body}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                        />
                        <InputError message={form.errors.body} />
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={form.data.body.trim() === ''}
                                loading={form.processing}
                                data-test="job-note-add"
                            >
                                {t('jobs.notes_tab.add')}
                            </Button>
                        </div>
                    </form>
                )}
                {notes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('jobs.notes_tab.empty')}
                    </p>
                ) : (
                    <ul className="space-y-2" data-test="job-notes">
                        {notes.map((note) => (
                            <li key={note.id} className="da-card p-3">
                                <p className="text-sm break-words whitespace-pre-wrap">
                                    {note.body}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {[note.author, time.dateTime(note.at)]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                {/* Delete on its own line at the left: the right edge is where the "+" floats. */}
                                {note.can_delete && (
                                    <button
                                        type="button"
                                        className="inline-flex min-h-11 items-center gap-1 text-xs font-medium text-destructive"
                                        onClick={() => remove(note)}
                                        data-test="job-note-delete"
                                    >
                                        <Trash2 className="size-4" />
                                        {t('jobs.notes_tab.delete')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </div>
    );
}
