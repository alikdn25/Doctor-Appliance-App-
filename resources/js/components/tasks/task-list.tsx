import { router, usePage } from '@inertiajs/react';
import { Bell, Check, ListTodo } from 'lucide-react';
import { dayLabel } from '@/components/calendar/types';
import type { TaskItem } from '@/components/tasks/types';
import { useClock } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { done as doneRoute } from '@/routes/tasks';

/**
 * Tasks in their own colour (pink), above the jobs of My Jobs and the calendar: the time or "All day", the title and,
 * for someone else's task, who it is for. The round tick marks one done; a tap on the row opens it.
 */
export function TaskList({
    tasks,
    onOpen,
    byDay = false,
    showOwner = false,
}: {
    tasks: TaskItem[];
    onOpen: (task: TaskItem) => void;
    /** Week views: a day heading before each day's tasks. */
    byDay?: boolean;
    /** The calendar shows whose task it is. */
    showOwner?: boolean;
}) {
    const t = useTrans();
    const clock = useClock();
    const locale = usePage().props.auth.company?.locale ?? 'en-US';

    if (tasks.length === 0) return null;

    const toggle = (task: TaskItem) =>
        router.post(
            doneRoute(task.id).url,
            { done: !task.done },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <section
            className="da-card overflow-hidden"
            aria-label={t('tasks.list')}
            data-test="task-list"
        >
            <h2 className="flex items-center gap-2 px-4 pt-3 pb-1 text-sm font-semibold text-[var(--task-text)]">
                <ListTodo className="size-4" /> {t('tasks.list')}
            </h2>
            <ul className="divide-y">
                {tasks.map((task, i) => (
                    <li key={task.id}>
                        {byDay && tasks[i - 1]?.date !== task.date && (
                            <p className="px-4 pt-2 text-xs font-semibold text-muted-foreground">
                                {dayLabel(task.date, locale)}
                            </p>
                        )}
                        <div
                            className="da-task flex items-center gap-2 border-l-4 pr-2"
                            data-test="task-row"
                            data-done={task.done || undefined}
                        >
                            <button
                                type="button"
                                aria-label={
                                    task.done
                                        ? t('tasks.mark_not_done')
                                        : t('tasks.mark_done')
                                }
                                aria-pressed={task.done}
                                disabled={!task.can_edit}
                                onClick={() => toggle(task)}
                                className="flex size-11 shrink-0 items-center justify-center"
                                data-test="task-toggle"
                            >
                                <span
                                    className={cn(
                                        'flex size-6 items-center justify-center rounded-full border-2 border-[var(--task-to)]',
                                        task.done &&
                                            'bg-[var(--task-to)] text-white',
                                    )}
                                >
                                    {task.done && <Check className="size-4" />}
                                </span>
                            </button>
                            <button
                                type="button"
                                className="min-w-0 flex-1 py-2.5 text-left"
                                onClick={() => onOpen(task)}
                            >
                                <span className="flex items-center gap-1.5 text-xs font-semibold text-[var(--task-text)]">
                                    {task.all_day
                                        ? t('tasks.all_day')
                                        : task.end_time
                                          ? clock.range(
                                                task.start_time ?? '',
                                                task.end_time,
                                            )
                                          : clock.clock(task.start_time ?? '')}
                                    {task.remind_minutes !== null && (
                                        <Bell
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'block truncate text-sm font-medium',
                                        task.done &&
                                            'text-muted-foreground line-through',
                                    )}
                                >
                                    {task.title}
                                </span>
                                {showOwner && task.user_name && (
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {t('tasks.for_person', {
                                            name: task.user_name,
                                        })}
                                    </span>
                                )}
                            </button>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
