import { History } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show } from '@/routes/history';

export type HistoryEntry = {
    id: number;
    at: string | null;
    action: string;
    event: string;
    type: string;
    record: string | number | null;
    record_id: number | null;
    who: string | null;
    source: string;
    fields: { field: string; old: string | null; new: string | null }[];
};

/** One change: who, when, what, and each field from the old to the new value. */
export function HistoryEntryRow({
    entry,
    showRecord = false,
}: {
    entry: HistoryEntry;
    showRecord?: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const event = t(`history.events.${entry.event}`);
    const label =
        event === `history.events.${entry.event}`
            ? entry.action.replace(/[._]/g, ' ')
            : event;

    return (
        <li className="space-y-1 p-3 text-sm" data-test="history-entry">
            <p className="flex flex-wrap items-baseline gap-x-2">
                <span className="font-semibold">
                    {showRecord
                        ? `${t(`history.types.${entry.type}`)} ${entry.record ?? ''} · ${label}`
                        : label}
                </span>
                <span className="text-xs text-muted-foreground">
                    {[
                        entry.at ? time.dateTime(entry.at) : null,
                        entry.who ?? t('history.nobody'),
                        entry.source !== 'web'
                            ? t(`history.sources.${entry.source}`)
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </p>
            {entry.fields.length > 0 && (
                <dl className="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-xs">
                    {entry.fields.map((f) => (
                        <div key={f.field} className="contents">
                            <dt className="text-muted-foreground">{f.field}</dt>
                            <dd className="min-w-0 break-words">
                                {f.old !== null
                                    ? t('history.from_to', {
                                          old: f.old,
                                          new: f.new ?? '—',
                                      })
                                    : (f.new ?? '—')}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </li>
    );
}

/**
 * History of a customer, job, estimate or invoice, for the Owner: loaded when opened.
 */
export function RecordHistory({
    type,
    id,
}: {
    type: 'customer' | 'job' | 'estimate' | 'invoice';
    id: number;
}) {
    const t = useTrans();
    const [entries, setEntries] = useState<HistoryEntry[] | null>(null);
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<'idle' | 'loading' | 'failed'>('idle');

    const toggle = async () => {
        if (open) {
            setOpen(false);

            return;
        }

        setOpen(true);
        setState('loading');

        try {
            const response = await fetch(show({ type, id }).url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setEntries((await response.json()).entries);
            setState('idle');
        } catch {
            setState('failed');
        }
    };

    return (
        <section className="space-y-2" data-test="record-history">
            <Button
                type="button"
                variant="outline"
                className="h-11"
                onClick={toggle}
                aria-expanded={open}
            >
                <History /> {open ? t('history.hide') : t('history.show')}
            </Button>
            {open && state === 'loading' && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> {t('history.loading')}
                </p>
            )}
            {open && state === 'failed' && (
                <p className="text-sm text-destructive">
                    {t('history.failed')}
                </p>
            )}
            {open &&
                state === 'idle' &&
                entries !== null &&
                (entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('history.empty')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card shadow-sm">
                        {entries.map((entry) => (
                            <HistoryEntryRow key={entry.id} entry={entry} />
                        ))}
                    </ul>
                ))}
        </section>
    );
}
