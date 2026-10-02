import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { toggle } from '@/routes/jobs/checklist';

export type ChecklistItemData = {
    id: number;
    label: string;
    is_done: boolean;
    done_by: string | null;
    done_at: string | null;
};

/**
 * Checklist of the job type. The whole row is the tap target (one-handed use).
 */
export function ChecklistSection({
    jobId,
    items,
    canTick,
}: {
    jobId: number;
    items: ChecklistItemData[];
    canTick: boolean;
}) {
    const t = useTrans();
    // Ticks show at once; the server answer replaces them.
    const [local, setLocal] = useState(items);

    useEffect(() => setLocal(items), [items]);

    if (items.length === 0) {
        return null;
    }

    const done = local.filter((i) => i.is_done).length;

    const tick = (item: ChecklistItemData) => {
        setLocal((all) =>
            all.map((i) =>
                i.id === item.id ? { ...i, is_done: !item.is_done } : i,
            ),
        );
        router.put(
            toggle([jobId, item.id]).url,
            { is_done: !item.is_done },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['job'],
                onError: () => setLocal(items),
            },
        );
    };

    return (
        <section className="space-y-2">
            <div className="flex items-baseline justify-between">
                <h2 className="text-base font-medium">
                    {t('jobs.checklist.title')}
                </h2>
                <span className="text-xs text-muted-foreground">
                    {t('jobs.checklist.progress', {
                        done,
                        total: local.length,
                    })}
                </span>
            </div>
            <ul className="divide-y rounded-lg border">
                {local.map((item) => (
                    <li key={item.id}>
                        <button
                            type="button"
                            disabled={!canTick}
                            onClick={() => tick(item)}
                            className="flex min-h-12 w-full items-center gap-3 px-3 py-2 text-left disabled:cursor-default"
                            aria-pressed={item.is_done}
                        >
                            <span
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-md border',
                                    item.is_done &&
                                        'border-emerald-600 bg-emerald-600 text-white',
                                )}
                            >
                                {item.is_done && <Check className="size-4" />}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span
                                    className={cn(
                                        'block text-sm',
                                        item.is_done &&
                                            'text-muted-foreground line-through',
                                    )}
                                >
                                    {item.label}
                                </span>
                                {item.is_done && item.done_by && (
                                    <span className="block text-xs text-muted-foreground">
                                        {item.done_by}
                                    </span>
                                )}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}
