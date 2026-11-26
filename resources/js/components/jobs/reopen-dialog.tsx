import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { NotifyClientBox } from '@/components/jobs/job-edit-sheet';
import type { Assignable } from '@/components/jobs/types';
import { Button } from '@/components/ui/button';
import { DateChips } from '@/components/ui/date-chips';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import type { TimeWindow } from '@/components/ui/time-window-chips';
import { TimeWindowChips } from '@/components/ui/time-window-chips';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { reopen } from '@/routes/jobs';

/** 'now', 'none' (No time), 'no_date', or a window's start ("09:00"). */
type Slot = string;

const plusMinutes = (hhmm: string, minutes: number) => {
    const [h, m] = hhmm.split(':').map(Number);
    const total = Math.min(h * 60 + m + minutes, 23 * 60 + 59);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
};

/**
 * "Reopen job" (owner's task of November 26): a new visit on the same closed job — a day, an arrival window, Now, No
 * time or No date; the technician is "Me" by default; "Notify client" (off by default) sends the booking
 * confirmation. Reopen opens the job again; notes, photos, invoices and payments stay.
 */
export function ReopenDialog({
    open,
    onOpenChange,
    jobId,
    today,
    nowTime,
    windows,
    assignableUsers,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    today: string;
    nowTime: string;
    windows: TimeWindow[];
    assignableUsers: Assignable[];
}) {
    const t = useTrans();
    const me = usePage().props.auth.user?.id ?? null;
    const form = useForm({
        date: today,
        slot: 'now' as Slot,
        technician: '',
        notify: false,
    });

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        const mine = assignableUsers.some((u) => u.id === me);
        form.setData({
            date: today,
            slot: 'now',
            technician: mine && me !== null ? String(me) : '',
            notify: false,
        });
        // Reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const isToday = form.data.date === today;
    const slot = form.data.slot;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => {
            const window = windows.find((w) => w.start === d.slot);
            const start = d.slot === 'now' ? nowTime : (window?.start ?? null);
            const end =
                d.slot === 'now'
                    ? plusMinutes(nowTime, 120)
                    : (window?.end ?? null);

            return {
                date: d.slot === 'no_date' ? null : d.date,
                start_time: d.slot === 'no_date' ? null : start,
                end_time: d.slot === 'no_date' ? null : end,
                no_date: d.slot === 'no_date',
                assignee_ids: d.technician === '' ? [] : [Number(d.technician)],
                notify: d.notify,
            };
        });
        form.post(reopen(jobId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90svh] overflow-y-auto sm:max-w-xl"
                data-test="reopen-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{t('jobs.reopen.title')}</DialogTitle>
                    <DialogDescription>
                        {t('jobs.reopen.hint')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div
                        className={cn(
                            'space-y-3',
                            slot === 'no_date' && 'opacity-50',
                        )}
                    >
                        <DateChips
                            label={t('jobs.quick.day')}
                            today={today}
                            value={form.data.date}
                            onChange={(date) =>
                                form.setData({
                                    ...form.data,
                                    date,
                                    slot:
                                        slot === 'no_date' ||
                                        (slot === 'now' && date !== today)
                                            ? (windows[0]?.start ?? 'none')
                                            : slot,
                                })
                            }
                        />
                        <Input
                            type="date"
                            aria-label={t('jobs.visit_fields.date')}
                            value={form.data.date}
                            min={today}
                            onChange={(e) =>
                                form.setData('date', e.target.value)
                            }
                            data-test="reopen-date"
                        />
                        <TimeWindowChips
                            label={t('jobs.quick.arrival_window')}
                            windows={windows}
                            nowTime={isToday ? nowTime : null}
                            leading={
                                isToday
                                    ? {
                                          value: 'now',
                                          label: t('jobs.quick.now'),
                                      }
                                    : undefined
                            }
                            trailing={{
                                value: 'none',
                                label: t('jobs.quick.no_time'),
                            }}
                            value={slot === 'no_date' ? null : slot}
                            onChange={(value) => form.setData('slot', value)}
                        />
                    </div>
                    <button
                        type="button"
                        className="da-chip da-press inline-flex min-h-11 items-center rounded-2xl px-3 text-sm font-semibold"
                        data-state={slot === 'no_date' ? 'on' : 'off'}
                        onClick={() => form.setData('slot', 'no_date')}
                        data-test="reopen-no-date"
                    >
                        {t('jobs.reopen.no_date')}
                    </button>
                    {assignableUsers.length > 0 && (
                        <FormField
                            id="reopen-technician"
                            label={t('jobs.quick.technician')}
                            error={
                                (form.errors as Record<string, string>)[
                                    'assignee_ids.0'
                                ]
                            }
                        >
                            <NativeSelect
                                id="reopen-technician"
                                value={form.data.technician}
                                onChange={(e) =>
                                    form.setData('technician', e.target.value)
                                }
                            >
                                <option value="">—</option>
                                {assignableUsers.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.id === me
                                            ? t('jobs.reopen.me')
                                            : user.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    )}
                    <NotifyClientBox
                        id="reopen-notify"
                        checked={form.data.notify}
                        onChange={(notify) => form.setData('notify', notify)}
                    />
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        loading={form.processing}
                        disabled={form.processing}
                        data-test="reopen-submit"
                    >
                        {t('jobs.reopen.submit')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
