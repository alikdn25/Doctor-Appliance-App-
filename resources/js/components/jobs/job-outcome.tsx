import { Link, router } from '@inertiajs/react';
import { AlarmClock, Flag, Link2, PackageCheck } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show } from '@/routes/jobs';
import { toggle } from '@/routes/jobs/bring';

export type BringItemData = {
    id: number;
    description: string;
    quantity: string;
    is_checked: boolean;
    checked_by: string | null;
};

/**
 * How the job ended: outcome, reason, comment, who closed it and when.
 */
export function OutcomeCard({
    outcome,
    label,
    reason,
    note,
    closedAt,
    closedBy,
}: {
    outcome: string;
    label: string;
    reason: string | null;
    note: string | null;
    closedAt: string | null;
    closedBy: string | null;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const tone =
        outcome === 'repaired'
            ? 'border-emerald-600/40 bg-emerald-50 dark:bg-emerald-950'
            : 'border-amber-600/40 bg-amber-50 dark:bg-amber-950';

    return (
        <section className={`space-y-1 rounded-lg border p-3 text-sm ${tone}`}>
            <p className="flex items-center gap-2 font-medium">
                <Flag className="size-4" />
                {[label, reason].filter(Boolean).join(' — ')}
            </p>
            {note && <p className="whitespace-pre-line">{note}</p>}
            {closedAt && (
                <p className="text-xs text-muted-foreground">
                    {time.dateTime(closedAt)}
                    {closedBy && ` · ${t('jobs.close.by', { name: closedBy })}`}
                </p>
            )}
        </section>
    );
}

/**
 * Links between a return visit / callback and the job it follows up.
 */
export function FollowUpLinks({
    visitTypeLabel,
    previous,
    followUps,
}: {
    visitTypeLabel: string;
    previous: { id: number; number: number } | null;
    followUps: {
        id: number;
        number: number;
        visit_type_label: string;
        status_label: string;
    }[];
}) {
    const t = useTrans();

    if (!previous && followUps.length === 0) {
        return null;
    }

    return (
        <section className="space-y-1 rounded-lg border p-3 text-sm">
            {previous && (
                <p className="flex items-center gap-2">
                    <Link2 className="size-4" />
                    {visitTypeLabel} · {t('jobs.previous_job')}{' '}
                    <Link
                        href={show(previous.id)}
                        className="font-medium underline"
                    >
                        #{previous.number}
                    </Link>
                </p>
            )}
            {followUps.length > 0 && (
                <div>
                    <p className="text-xs text-muted-foreground">
                        {t('jobs.follow_ups')}
                    </p>
                    <ul>
                        {followUps.map((f) => (
                            <li key={f.id}>
                                <Link href={show(f.id)} className="underline">
                                    #{f.number}
                                </Link>{' '}
                                · {f.visit_type_label} · {f.status_label}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

/**
 * "Bring with you": parts and materials for a return visit, ticked one-handed while loading the van.
 */
export function BringList({
    jobId,
    items,
    canTick,
}: {
    jobId: number;
    items: BringItemData[];
    canTick: boolean;
}) {
    const t = useTrans();
    const done = items.filter((i) => i.is_checked).length;

    if (items.length === 0) {
        return null;
    }

    const tick = (item: BringItemData, checked: boolean) =>
        router.put(
            toggle({ job: jobId, item: item.id }).url,
            { is_checked: checked },
            { preserveScroll: true },
        );

    return (
        <section className="space-y-2">
            <div className="flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-base font-medium">
                    <PackageCheck className="size-4" />
                    {t('jobs.bring.title')}
                </h2>
                <span className="text-sm text-muted-foreground">
                    {t('jobs.bring.loaded', { done, total: items.length })}
                </span>
            </div>
            <ul className="divide-y rounded-lg border">
                {items.map((item) => (
                    <li key={item.id}>
                        <label className="flex min-h-12 items-center gap-3 px-3 py-2 text-sm">
                            <Checkbox
                                className="size-6"
                                checked={item.is_checked}
                                disabled={!canTick}
                                onCheckedChange={(c) => tick(item, c === true)}
                            />
                            <span
                                className={
                                    item.is_checked
                                        ? 'flex-1 text-muted-foreground line-through'
                                        : 'flex-1'
                                }
                            >
                                {item.description}
                            </span>
                            <span className="font-medium tabular-nums">
                                × {item.quantity}
                            </span>
                        </label>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Red "strict arrival" mark for a visit (calendar, lists, technician screens).
 */
export function StrictBadge({ className = '' }: { className?: string }) {
    const t = useTrans();

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white ${className}`}
        >
            <AlarmClock className="size-3" />
            {t('jobs.strict.badge')}
        </span>
    );
}
