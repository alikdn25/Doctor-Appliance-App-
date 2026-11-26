import { router, useForm, usePage } from '@inertiajs/react';
import { Check, ListTodo, PenLine, Sparkles, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import type { TaskDraft, TaskItem } from '@/components/tasks/types';
import {
    REMINDERS,
    TASK_TYPE_CHOICES,
    taskIcon,
} from '@/components/tasks/types';
import { AddressAutocomplete } from '@/components/customers/address-autocomplete';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { holdKeyboard } from '@/lib/keyboard-handoff';
import { cn } from '@/lib/utils';
import { index as assistantPage } from '@/routes/assistant';
import {
    destroy,
    done as doneRoute,
    people as peopleRoute,
    store,
    update,
} from '@/routes/tasks';
import type { Option } from '@/types';

/** "Today" in the company's time zone, as 2026-10-24. */
function companyToday(timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone }).format(new Date());
}

/** The next whole hour from now in the company's time zone, as "14:00". */
function nextHour(timeZone: string): string {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour: '2-digit',
            hour12: false,
        }).format(new Date()),
    );

    return `${String(Math.min(hour + 1, 23)).padStart(2, '0')}:00`;
}

const plusHour = (hhmm: string) => {
    const [h, m] = hhmm.split(':').map(Number);

    return `${String(Math.min(h + 1, 23)).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
};

/**
 * "+ → Task" (owner's task, Oct 24): a to-do without a customer — title, day, a time or all day, note, who it is for
 * (oneself, or a teammate for the office) and a reminder (push and email). Editing the same sheet also marks it done
 * or deletes it. A new task starts with two buttons like a booking (owner's task of November 26): "✨ Assistant"
 * (only for people with the assistant) closes the sheet and opens the assistant with the keyboard up; "Manually"
 * puts the cursor in Title.
 */
export function TaskSheet({
    open,
    onOpenChange,
    task = null,
    date,
    draft = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The task to edit; null for a new one. */
    task?: TaskItem | null;
    /** The day a new task starts on (the calendar's day). */
    date?: string | null;
    /** A new task read from the To Do microphone. */
    draft?: TaskDraft | null;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const timeZone = auth.company?.timezone ?? 'UTC';
    const [people, setPeople] = useState<Option[]>([]);
    const [jobs, setJobs] = useState<Option[]>([]);
    const titleRef = useRef<HTMLInputElement>(null);
    const form = useForm({
        title: '',
        note: '',
        task_date: '',
        all_day: false,
        start_time: '',
        end_time: '',
        user_id: '',
        remind_minutes: '' as string,
        kind: '' as string,
        address: '',
        service_job_id: '',
        repeat: 'none',
    });

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        const start =
            task?.start_time ?? draft?.start_time ?? nextHour(timeZone);
        form.setData({
            title: task?.title ?? draft?.title ?? '',
            note: task?.note ?? draft?.note ?? '',
            task_date:
                task?.date ??
                draft?.task_date ??
                date ??
                companyToday(timeZone),
            all_day: task?.all_day ?? draft?.all_day ?? false,
            start_time: start,
            end_time: task ? (task.end_time ?? '') : plusHour(start),
            user_id: task ? String(task.user_id) : '',
            remind_minutes:
                task?.remind_minutes === null ||
                task?.remind_minutes === undefined
                    ? task
                        ? ''
                        : '15'
                    : String(task.remind_minutes),
            // A new to-do is "Personal" (🏠) unless another type is picked.
            kind: task ? (task.kind ?? '') : (draft?.kind ?? 'home'),
            address: task?.address ?? draft?.address ?? '',
            service_job_id: task?.job
                ? String(task.job.id)
                : draft?.service_job_id
                  ? String(draft.service_job_id)
                  : '',
            repeat: task?.repeat ?? 'none',
        });

        // Who it can be for: only the office gets a list (others' tasks are always their own).
        const controller = new AbortController();
        fetch(peopleRoute().url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { people: Option[]; jobs?: Option[] } | null) => {
                setPeople(data?.people ?? []);
                setJobs(data?.jobs ?? []);
            })
            .catch(() => undefined);

        return () => controller.abort();
        // Only when the sheet opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, task?.id, draft]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            user_id: d.user_id === '' ? null : Number(d.user_id),
            remind_minutes:
                d.remind_minutes === '' ? null : Number(d.remind_minutes),
            end_time: d.end_time === '' ? null : d.end_time,
            kind: d.kind === '' ? null : d.kind,
            address: d.address.trim() === '' ? null : d.address,
            service_job_id:
                d.service_job_id === '' ? null : Number(d.service_job_id),
            // A Home task is always one's own.
            ...(d.kind === 'home' ? { user_id: null } : {}),
        }));
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (task) {
            form.put(update(task.id).url, options);
        } else {
            form.post(store().url, options);
        }
    };

    const toggleDone = () => {
        if (!task) return;
        router.post(
            doneRoute(task.id).url,
            { done: !task.done },
            { preserveScroll: true, onSuccess: () => onOpenChange(false) },
        );
    };

    const remove = () => {
        if (!task || !confirm(t('tasks.confirm_delete'))) return;
        router.delete(destroy(task.id).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const readOnly = task !== null && !task.can_edit;
    const assistant = !task && auth.can?.useAssistant === true;

    const openAssistant = () => {
        // During the tap, so the phone opens the keyboard (see keyboard-handoff).
        holdKeyboard();
        onOpenChange(false);
        router.visit(assistantPage({ query: { focus: 1 } }));
    };

    const startManually = () => titleRef.current?.focus();

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[92dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="task-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle className="flex items-center gap-2">
                        <ListTodo className="size-5" />
                        {task ? t('tasks.edit') : t('tasks.new')}
                    </SheetTitle>
                    <SheetDescription>
                        {t('tasks.description')}
                    </SheetDescription>
                </SheetHeader>
                {!task && (
                    <div className="flex w-full gap-2" data-test="task-start">
                        {assistant && (
                            <button
                                type="button"
                                onClick={openAssistant}
                                className="da-press da-quick-booking inline-flex min-h-[3.9rem] min-w-0 flex-1 flex-col items-center justify-center gap-1 rounded-2xl px-2 py-2 text-center text-[15px] leading-tight font-semibold [&_svg]:size-[1.625rem] [&_svg]:shrink-0"
                                data-test="task-assistant"
                            >
                                <Sparkles />
                                <span>{t('tasks.start.assistant')}</span>
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={startManually}
                            className="da-press da-raised inline-flex min-h-[3.9rem] min-w-0 flex-1 flex-col items-center justify-center gap-1 rounded-2xl px-2 py-2 text-center text-[15px] leading-tight font-semibold [&_svg]:size-[1.625rem] [&_svg]:shrink-0"
                            data-test="task-manually"
                        >
                            <PenLine />
                            <span>{t('tasks.start.manually')}</span>
                        </button>
                    </div>
                )}
                <form onSubmit={submit} className="space-y-3">
                    <FormField
                        id="task-title"
                        label={t('tasks.fields.title')}
                        error={form.errors.title}
                    >
                        <Input
                            ref={titleRef}
                            id="task-title"
                            value={form.data.title}
                            maxLength={160}
                            placeholder={t('tasks.title_placeholder')}
                            disabled={readOnly}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid grid-cols-[1fr_auto] items-end gap-2">
                        <FormField
                            id="task-date"
                            label={t('tasks.fields.task_date')}
                            error={form.errors.task_date}
                        >
                            <Input
                                id="task-date"
                                type="date"
                                value={form.data.task_date}
                                disabled={readOnly}
                                onChange={(e) =>
                                    form.setData('task_date', e.target.value)
                                }
                            />
                        </FormField>
                        <SegmentedControl
                            label={t('tasks.all_day')}
                            className="h-12"
                            value={form.data.all_day ? 'day' : 'time'}
                            onChange={(value) =>
                                !readOnly &&
                                form.setData('all_day', value === 'day')
                            }
                            options={[
                                { value: 'time', label: t('tasks.timed') },
                                { value: 'day', label: t('tasks.all_day') },
                            ]}
                        />
                    </div>
                    {!form.data.all_day && (
                        <div className="grid grid-cols-2 gap-2">
                            <FormField
                                id="task-start"
                                label={t('tasks.fields.start_time')}
                                error={form.errors.start_time}
                            >
                                <Input
                                    id="task-start"
                                    type="time"
                                    value={form.data.start_time}
                                    disabled={readOnly}
                                    onChange={(e) =>
                                        form.setData(
                                            'start_time',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="task-end"
                                label={t('tasks.fields.end_time')}
                                error={form.errors.end_time}
                            >
                                <Input
                                    id="task-end"
                                    type="time"
                                    value={form.data.end_time}
                                    disabled={readOnly}
                                    onChange={(e) =>
                                        form.setData('end_time', e.target.value)
                                    }
                                />
                            </FormField>
                        </div>
                    )}
                    {/* Type (owner's task of November 25): 🛒 shop / parts, 🏠 personal, 📞 call. */}
                    <FormField
                        id="task-kind"
                        label={t('tasks.types.label')}
                        error={form.errors.kind}
                    >
                        <div
                            id="task-kind"
                            role="radiogroup"
                            aria-label={t('tasks.types.label')}
                            className="grid grid-cols-3 gap-2"
                            data-test="task-kind"
                        >
                            {TASK_TYPE_CHOICES.map((kind) => (
                                <button
                                    key={kind}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.kind === kind}
                                    disabled={readOnly}
                                    onClick={() => form.setData('kind', kind)}
                                    data-test={`task-kind-${kind}`}
                                    className={cn(
                                        'da-press flex min-h-11 items-center justify-center gap-1.5 rounded-xl border px-2 text-sm font-medium',
                                        form.data.kind === kind &&
                                            'border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100',
                                    )}
                                >
                                    <span aria-hidden="true">
                                        {taskIcon(kind)}
                                    </span>
                                    {t(`tasks.types.${kind}`)}
                                </button>
                            ))}
                        </div>
                    </FormField>
                    <div className="grid grid-cols-2 gap-2">
                        <FormField
                            id="task-repeat"
                            label={t('tasks.fields.repeat')}
                            error={form.errors.repeat}
                        >
                            <NativeSelect
                                id="task-repeat"
                                value={form.data.repeat}
                                disabled={readOnly}
                                onChange={(e) =>
                                    form.setData('repeat', e.target.value)
                                }
                            >
                                {(['none', 'weekly', 'monthly'] as const).map(
                                    (repeat) => (
                                        <option key={repeat} value={repeat}>
                                            {t(`tasks.repeats.${repeat}`)}
                                        </option>
                                    ),
                                )}
                            </NativeSelect>
                        </FormField>
                    </div>
                    {form.data.kind === 'home' && (
                        <p className="text-xs text-muted-foreground">
                            {t('tasks.home_hint')}
                        </p>
                    )}
                    <FormField
                        id="task-address"
                        label={t('tasks.fields.address')}
                        error={form.errors.address}
                    >
                        <AddressAutocomplete
                            id="task-address"
                            value={form.data.address}
                            maxLength={500}
                            disabled={readOnly}
                            country={auth.company?.country ?? 'US'}
                            onChange={(value) => form.setData('address', value)}
                            onPick={(_, text) =>
                                form.setData('address', text.slice(0, 500))
                            }
                        />
                    </FormField>
                    {(jobs.length > 0 || form.data.service_job_id !== '') && (
                        <FormField
                            id="task-job"
                            label={t('tasks.fields.service_job_id')}
                            error={form.errors.service_job_id}
                        >
                            <NativeSelect
                                id="task-job"
                                value={form.data.service_job_id}
                                disabled={readOnly}
                                onChange={(e) =>
                                    form.setData(
                                        'service_job_id',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">{t('tasks.no_job')}</option>
                                {!task &&
                                    draft?.service_job_id &&
                                    !jobs.some(
                                        (j) =>
                                            j.value ===
                                            String(draft.service_job_id),
                                    ) && (
                                        <option
                                            value={String(draft.service_job_id)}
                                        >
                                            {draft.job_label ??
                                                `#${draft.service_job_id}`}
                                        </option>
                                    )}
                                {task?.job &&
                                    !jobs.some(
                                        (j) => j.value === String(task.job!.id),
                                    ) && (
                                        <option value={String(task.job.id)}>
                                            #{task.job.number}{' '}
                                            {task.job.customer ?? ''}
                                        </option>
                                    )}
                                {jobs.map((job) => (
                                    <option key={job.value} value={job.value}>
                                        {job.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    )}
                    {people.length > 1 && form.data.kind !== 'home' && (
                        <FormField
                            id="task-user"
                            label={t('tasks.fields.user_id')}
                            error={form.errors.user_id}
                        >
                            <NativeSelect
                                id="task-user"
                                value={form.data.user_id}
                                disabled={readOnly}
                                onChange={(e) =>
                                    form.setData('user_id', e.target.value)
                                }
                            >
                                <option value="">{t('tasks.for_me')}</option>
                                {people
                                    .filter(
                                        (p) =>
                                            p.value !== String(auth.user?.id),
                                    )
                                    .map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.label}
                                        </option>
                                    ))}
                            </NativeSelect>
                        </FormField>
                    )}
                    <FormField
                        id="task-reminder"
                        label={t('tasks.fields.remind_minutes')}
                        error={form.errors.remind_minutes}
                    >
                        <NativeSelect
                            id="task-reminder"
                            value={form.data.remind_minutes}
                            disabled={readOnly}
                            onChange={(e) =>
                                form.setData('remind_minutes', e.target.value)
                            }
                        >
                            <option value="">
                                {t('tasks.reminders.none')}
                            </option>
                            {REMINDERS.map((minutes) => (
                                <option key={minutes} value={String(minutes)}>
                                    {t(`tasks.reminders.${minutes}`)}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="task-note"
                        label={t('tasks.fields.note')}
                        error={form.errors.note}
                    >
                        <Textarea
                            id="task-note"
                            rows={2}
                            maxLength={5000}
                            className="[field-sizing:content] min-h-16"
                            value={form.data.note}
                            disabled={readOnly}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </FormField>
                    {!readOnly && (
                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={form.processing}
                            loading={form.processing}
                            data-test="task-save"
                        >
                            {t('tasks.save')}
                        </Button>
                    )}
                    {task && task.can_edit && (
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={toggleDone}
                                data-test="task-done"
                            >
                                <Check />
                                {task.done
                                    ? t('tasks.mark_not_done')
                                    : t('tasks.mark_done')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive"
                                onClick={remove}
                                data-test="task-delete"
                            >
                                <Trash2 /> {t('tasks.delete')}
                            </Button>
                        </div>
                    )}
                </form>
            </SheetContent>
        </Sheet>
    );
}
