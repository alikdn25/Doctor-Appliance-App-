import { Head, Link, router } from '@inertiajs/react';
import { Check, Navigation, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { mapsUrl } from '@/components/customers/types';
import { ScreenHeader } from '@/components/screen-header';
import { TaskSheet } from '@/components/tasks/task-sheet';
import type { TaskItem } from '@/components/tasks/types';
import { taskIcon } from '@/components/tasks/types';
import { Button } from '@/components/ui/button';
import { useClock, useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { mine, show as showJob } from '@/routes/jobs';
import { useTaskDone } from '@/components/tasks/use-task-done';
import { destroy } from '@/routes/tasks';

type TaskPage = TaskItem & {
    planned_date: string | null;
    completed_at: string | null;
    can_delete: boolean;
};

/**
 * A to-do's own page (owner's task of November 25), from a tap on it in Today: type, title, when, address with
 * Navigate, note, who it is for, linked job, reminder and repeat; Done, Edit and Delete.
 */
export default function TaskShow({ task }: { task: TaskPage }) {
    const t = useTrans();
    const clock = useClock();
    const time = useCompanyTime();
    const [editing, setEditing] = useState(false);

    const when = [
        time.dateOnly(task.date),
        task.all_day || !task.start_time
            ? t('tasks.anytime')
            : task.end_time
              ? clock.range(task.start_time, task.end_time)
              : clock.clock(task.start_time),
    ].join(' · ');

    const done = useTaskDone({ preserveState: false });
    const toggle = () => done.toggle(task);

    const remove = () => {
        if (!window.confirm(t('tasks.page.delete_confirm'))) return;
        router.delete(destroy(task.id).url, {
            onSuccess: () => router.visit(mine().url),
        });
    };

    const rows: { label: string; value: ReactNode; test: string }[] = [
        { label: t('tasks.page.when'), value: when, test: 'when' },
        task.kind
            ? {
                  label: t('tasks.page.type'),
                  value: `${taskIcon(task.kind)} ${t(`tasks.kinds.${task.kind}`)}`,
                  test: 'type',
              }
            : null,
        task.note
            ? {
                  label: t('tasks.page.note'),
                  value: (
                      <span className="whitespace-pre-line">{task.note}</span>
                  ),
                  test: 'note',
              }
            : null,
        task.user_name
            ? {
                  label: t('tasks.page.for'),
                  value: task.user_name,
                  test: 'for',
              }
            : null,
        task.job
            ? {
                  label: t('tasks.page.job'),
                  value: (
                      <Link
                          href={showJob(task.job.id)}
                          className="text-link underline underline-offset-4"
                      >
                          #{task.job.number}
                          {task.job.customer ? ` ${task.job.customer}` : ''}
                      </Link>
                  ),
                  test: 'job',
              }
            : null,
        task.remind_minutes !== null
            ? {
                  label: t('tasks.page.reminder'),
                  value: t(`tasks.reminders.${task.remind_minutes}`),
                  test: 'reminder',
              }
            : null,
        task.repeat && task.repeat !== 'none'
            ? {
                  label: t('tasks.page.repeat'),
                  value: t(`tasks.repeats.${task.repeat}`),
                  test: 'repeat',
              }
            : null,
        task.planned_date && task.planned_date !== task.date
            ? {
                  label: t('tasks.carried_over'),
                  value: t('tasks.page.planned', {
                      date: time.dateOnly(task.planned_date),
                  }),
                  test: 'planned',
              }
            : null,
        task.completed_at
            ? {
                  label: t('tasks.page.completed_label'),
                  value: t('tasks.page.completed_at', {
                      time: time.dateTime(task.completed_at),
                  }),
                  test: 'completed',
              }
            : null,
    ].filter((row): row is NonNullable<typeof row> => row !== null);

    return (
        <>
            <Head title={task.title} />
            <ScreenHeader title={t('tasks.page.title')} back={mine()} />

            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6">
                <section
                    className="da-card da-todo-card space-y-1 border-l-4 p-4"
                    data-test="task-page"
                >
                    <p className="text-[11px] font-bold tracking-wide text-amber-800 uppercase dark:text-amber-300">
                        {t('tasks.todo_label')}
                    </p>
                    <h1 className="flex items-center gap-2 text-xl font-semibold">
                        <span aria-hidden="true">{taskIcon(task.kind)}</span>
                        <span
                            className={
                                task.done
                                    ? 'text-muted-foreground line-through'
                                    : undefined
                            }
                        >
                            {task.title}
                        </span>
                    </h1>
                    {(task.carried_days ?? 0) > 0 && !task.done && (
                        <span className="inline-flex rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white">
                            {t('tasks.carried_over')}
                        </span>
                    )}
                </section>

                <dl className="da-card divide-y overflow-hidden">
                    {rows.map((row) => (
                        <div
                            key={row.test}
                            className="flex gap-3 px-4 py-3 text-sm"
                            data-test={`task-${row.test}`}
                        >
                            <dt className="w-28 shrink-0 text-muted-foreground">
                                {row.label}
                            </dt>
                            <dd className="min-w-0 flex-1 font-medium">
                                {row.value}
                            </dd>
                        </div>
                    ))}
                    {task.address && (
                        <div
                            className="flex items-center gap-3 px-4 py-3 text-sm"
                            data-test="task-address"
                        >
                            <dt className="w-28 shrink-0 text-muted-foreground">
                                {t('tasks.page.address')}
                            </dt>
                            <dd className="min-w-0 flex-1 font-medium">
                                {task.address}
                                {task.on_the_way && (
                                    <span className="block text-xs text-emerald-700 dark:text-emerald-400">
                                        {task.on_the_way}
                                    </span>
                                )}
                            </dd>
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={mapsUrl(task.address)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Navigation /> {t('jobs.navigate')}
                                </a>
                            </Button>
                        </div>
                    )}
                </dl>

                {task.can_edit && (
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            className="col-span-2 h-11 min-h-11"
                            onClick={toggle}
                            data-test="task-page-done"
                        >
                            <Check />
                            {task.done
                                ? t('tasks.page.not_done')
                                : t('tasks.page.done')}
                        </Button>
                        <Button
                            variant="outline"
                            className="h-11 min-h-11"
                            onClick={() => setEditing(true)}
                            data-test="task-page-edit"
                        >
                            <Pencil /> {t('tasks.page.edit')}
                        </Button>
                        {task.can_delete && (
                            <Button
                                variant="outline"
                                className="h-11 min-h-11 text-danger"
                                onClick={remove}
                                data-test="task-page-delete"
                            >
                                <Trash2 /> {t('tasks.page.delete')}
                            </Button>
                        )}
                    </div>
                )}
            </div>

            <TaskSheet
                open={editing}
                onOpenChange={setEditing}
                task={editing ? task : null}
            />
            {done.dialog}
        </>
    );
}

TaskShow.layout = {
    breadcrumbs: [{ title: 'tasks.page.title', href: mine() }],
};
