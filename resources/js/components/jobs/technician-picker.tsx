import { usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
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
import { load } from '@/routes/technicians';

type Window = { start: string; end: string };

export type TechnicianLoad = {
    id: number;
    name: string;
    visits: { id: number; job_id: number; start: string; end: string }[];
};

/** "11–1" for a "11:00"–"13:00" window in the company's regional format. */
export function useHoursRange() {
    const { auth } = usePage().props;
    const locale = auth.company?.locale ?? 'en-US';
    const hours = useMemo(
        () =>
            new Intl.DateTimeFormat(locale, {
                hour: 'numeric',
                minute: '2-digit',
                timeZone: 'UTC',
            }),
        [locale],
    );

    return (w: Window) =>
        hours.formatRange(
            new Date(`1970-01-01T${w.start}:00Z`),
            new Date(`1970-01-01T${w.end}:00Z`),
        );
}

/**
 * Pick the technician in one tap (job page, My Jobs, Transfer "Move to…"): everyone who can take jobs, each with
 * their number of jobs that day and "free" or "busy 11–1" for the job's window.
 */
export function TechnicianPicker({
    open,
    onOpenChange,
    date,
    window,
    excludeVisitIds = [],
    currentId = null,
    excludeUserId = null,
    allowNone = false,
    title,
    onPick,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The day the load is counted for (YYYY-MM-DD, company time). */
    date: string;
    /** The job's arrival window that day; none = only the number of jobs. */
    window: Window | null;
    /** The job's own visits do not make its person busy (they still count in the day's jobs). */
    excludeVisitIds?: number[];
    currentId?: number | null;
    /** The person jobs are moved away from (Transfer). */
    excludeUserId?: number | null;
    allowNone?: boolean;
    title?: string;
    onPick: (userId: number | null) => void;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const range = useHoursRange();
    const [technicians, setTechnicians] = useState<TechnicianLoad[] | null>(
        null,
    );

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setTechnicians(null);
        fetch(load({ query: { date } }).url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((found: { technicians: TechnicianLoad[] } | null) =>
                setTechnicians(found?.technicians ?? []),
            )
            .catch(() => undefined);

        return () => controller.abort();
    }, [open, date]);

    const hint = (person: TechnicianLoad) => {
        const visits = person.visits.filter(
            (v) => !excludeVisitIds.includes(v.id),
        );
        const clash = window
            ? visits.find((v) => v.start < window.end && v.end > window.start)
            : undefined;

        // The count is the whole day's work. The person already on this job is said so (not "free"); for
        // everyone else "busy" means another job at this job's time.
        const hasThisJob = visits.length < person.visits.length;

        return [
            t('jobs.technician.jobs_count', { count: person.visits.length }),
            hasThisJob
                ? t('jobs.technician.has_this_job')
                : window &&
                  (clash
                      ? t('jobs.quick.busy', { time: range(clash) })
                      : t('jobs.quick.free')),
        ]
            .filter(Boolean)
            .join(' · ');
    };

    const choices = [
        ...(technicians ?? [])
            .filter((p) => p.id !== excludeUserId)
            .map((p) => ({
                id: p.id as number | null,
                name: p.name,
                hint: hint(p),
                busy:
                    !!window &&
                    p.visits.some(
                        (v) =>
                            !excludeVisitIds.includes(v.id) &&
                            v.start < window.end &&
                            v.end > window.start,
                    ),
            })),
        ...(allowNone && technicians
            ? [
                  {
                      id: null,
                      name: t('jobs.unassigned'),
                      hint: '',
                      busy: false,
                  },
              ]
            : []),
    ];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[80dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="technician-picker"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>
                        {title ?? t('jobs.technician.choose')}
                    </SheetTitle>
                    <SheetDescription>
                        {t('jobs.technician.load_on', {
                            day: new Intl.DateTimeFormat(
                                auth.company?.locale ?? 'en-US',
                                {
                                    weekday: 'short',
                                    month: 'short',
                                    day: 'numeric',
                                    timeZone: 'UTC',
                                },
                            ).format(new Date(`${date}T12:00:00Z`)),
                        })}
                    </SheetDescription>
                </SheetHeader>
                {technicians === null ? (
                    <div className="flex justify-center p-6">
                        <Spinner />
                    </div>
                ) : (
                    <ul className="-mx-1 space-y-1 overflow-y-auto px-1">
                        {choices.map((choice) => (
                            <li key={choice.id ?? 'none'}>
                                <button
                                    type="button"
                                    className="da-chip da-press flex min-h-14 w-full items-center gap-3 rounded-2xl px-4 text-left"
                                    data-state={
                                        choice.id === currentId ? 'on' : 'off'
                                    }
                                    onClick={() => onPick(choice.id)}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold">
                                            {choice.name}
                                        </span>
                                        {choice.hint && (
                                            <span
                                                className={cn(
                                                    'block text-xs',
                                                    choice.busy
                                                        ? 'text-amber-700 dark:text-amber-400'
                                                        : 'opacity-80',
                                                )}
                                            >
                                                {choice.hint}
                                            </span>
                                        )}
                                    </span>
                                    {choice.id === currentId && (
                                        <Check className="size-5 shrink-0" />
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </SheetContent>
        </Sheet>
    );
}
