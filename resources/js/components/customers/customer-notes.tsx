import { useTrans } from '@/lib/i18n';

/** Customer context stays attached to the person across all of their jobs. */
export function CustomerNotes({ notes }: { notes: string | null }) {
    const t = useTrans();
    if (!notes?.trim()) return null;

    return (
        <aside className="space-y-1 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
            <h3 className="text-sm font-semibold">
                {t('customers.fields.notes')}
            </h3>
            <p className="text-sm break-words whitespace-pre-wrap">{notes}</p>
        </aside>
    );
}
