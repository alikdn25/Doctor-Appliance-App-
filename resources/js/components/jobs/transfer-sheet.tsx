import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { TechnicianPicker } from '@/components/jobs/technician-picker';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { openVisits } from '@/routes/technicians';
import { transfer } from '@/routes/visits';

type OpenVisit = {
    id: number;
    date: string;
    start: string;
    end: string;
    job_number: number;
    customer: string | null;
    address: string | null;
};

/**
 * "Transfer" on a person's Team card: their open jobs from today on, each with a tick; "Move to…" gives the ticked
 * ones to another person. What is left can be ticked again and given to someone else.
 */
export function TransferSheet({
    membershipId,
    userId,
    name,
    onClose,
}: {
    membershipId: number;
    userId: number;
    name: string;
    onClose: () => void;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const locale = auth.company?.locale ?? 'en-US';
    const [visits, setVisits] = useState<OpenVisit[] | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [picking, setPicking] = useState(false);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        fetch(openVisits(membershipId).url, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((found: { visits: OpenVisit[] } | null) =>
                setVisits(found?.visits ?? []),
            )
            .catch(() => setVisits([]));
    }, [membershipId]);
    useEffect(load, [load]);

    const day = useMemo(
        () =>
            new Intl.DateTimeFormat(locale, {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                timeZone: 'UTC',
            }),
        [locale],
    );
    const hours = useMemo(
        () =>
            new Intl.DateTimeFormat(locale, {
                hour: 'numeric',
                minute: '2-digit',
                timeZone: 'UTC',
            }),
        [locale],
    );
    const range = (v: OpenVisit) =>
        hours.formatRange(
            new Date(`1970-01-01T${v.start}:00Z`),
            new Date(`1970-01-01T${v.end}:00Z`),
        );
    const ticked = (visits ?? []).filter((v) => selected.includes(v.id));
    const only = ticked.length === 1 ? ticked[0] : undefined;

    const move = (to: number | null) => {
        if (to === null) return;
        setPicking(false);
        setBusy(true);
        router.post(
            transfer().url,
            { visit_ids: selected, from_user_id: userId, to_user_id: to },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSelected([]);
                    load();
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <Sheet open onOpenChange={(open) => !open && onClose()}>
                <SheetContent
                    side="bottom"
                    className="mx-auto flex max-h-[90dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                    data-test="transfer-sheet"
                >
                    <SheetHeader className="p-0 pr-8">
                        <SheetTitle>
                            {t('jobs.transfer.action')} · {name}
                        </SheetTitle>
                        <SheetDescription>
                            {t('jobs.transfer.select_hint')}
                        </SheetDescription>
                    </SheetHeader>
                    {visits === null ? (
                        <div className="flex justify-center p-6">
                            <Spinner />
                        </div>
                    ) : visits.length === 0 ? (
                        <p className="da-card p-4 text-sm text-muted-foreground">
                            {t('jobs.transfer.none')}
                        </p>
                    ) : (
                        <ul className="-mx-1 min-h-0 flex-1 space-y-1 overflow-y-auto px-1">
                            {visits.map((visit) => {
                                const on = selected.includes(visit.id);

                                return (
                                    <li key={visit.id}>
                                        <button
                                            type="button"
                                            role="checkbox"
                                            aria-checked={on}
                                            className="da-chip da-press flex min-h-14 w-full items-center gap-3 rounded-2xl px-4 py-2 text-left"
                                            onClick={() =>
                                                setSelected((ids) =>
                                                    on
                                                        ? ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  visit.id,
                                                          )
                                                        : [...ids, visit.id],
                                                )
                                            }
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-5 shrink-0 items-center justify-center rounded border',
                                                    on &&
                                                        'border-link bg-link text-white',
                                                )}
                                            >
                                                {on && (
                                                    <Check className="size-3.5" />
                                                )}
                                            </span>
                                            <span className="min-w-0 flex-1 text-sm">
                                                <span className="block truncate font-semibold">
                                                    {day.format(
                                                        new Date(
                                                            `${visit.date}T12:00:00Z`,
                                                        ),
                                                    )}{' '}
                                                    · {range(visit)} ·{' '}
                                                    {visit.customer}
                                                </span>
                                                <span className="block truncate text-xs opacity-80">
                                                    #{visit.job_number}
                                                    {visit.address &&
                                                        ` · ${visit.address}`}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            disabled={selected.length === 0}
                            loading={busy}
                            onClick={() => setPicking(true)}
                            data-test="transfer-move"
                        >
                            {selected.length > 0
                                ? `${t('jobs.transfer.move_to')} (${selected.length})`
                                : t('jobs.transfer.move_to')}
                        </Button>
                        <Button variant="outline" onClick={onClose}>
                            {t('jobs.transfer.done')}
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>
            <TechnicianPicker
                open={picking}
                onOpenChange={setPicking}
                date={
                    only?.date ??
                    ticked[0]?.date ??
                    new Date().toISOString().slice(0, 10)
                }
                window={only ? { start: only.start, end: only.end } : null}
                excludeVisitIds={selected}
                excludeUserId={userId}
                title={t('jobs.transfer.move_title', {
                    count: selected.length,
                })}
                onPick={move}
            />
        </>
    );
}
