import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { notes as notesRoute } from '@/routes/customers';

/** "About the customer", edited in place. */
export function CustomerNotesEditor({
    customerId,
    notes,
    canUpdate,
}: {
    customerId: number;
    notes: string | null;
    canUpdate: boolean;
}) {
    const t = useTrans();
    const form = useForm({ notes: notes ?? '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(notesRoute(customerId).url, { preserveScroll: true });
    };

    if (!canUpdate) {
        return (
            <p className="da-card p-4 text-sm whitespace-pre-wrap">
                {notes?.trim() ? notes : t('customers.no_notes')}
            </p>
        );
    }

    return (
        <form onSubmit={submit} className="da-card space-y-2 p-4">
            <label htmlFor="customer-notes" className="text-sm font-semibold">
                {t('customers.fields.notes')}
            </label>
            <Textarea
                id="customer-notes"
                rows={4}
                maxLength={10000}
                className="[field-sizing:content] min-h-24"
                value={form.data.notes}
                onChange={(event) => form.setData('notes', event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
                {t('customers.notes_hint')}
            </p>
            {form.isDirty && (
                <div className="flex justify-end">
                    <Button type="submit" loading={form.processing}>
                        {t('common.save')}
                    </Button>
                </div>
            )}
        </form>
    );
}
