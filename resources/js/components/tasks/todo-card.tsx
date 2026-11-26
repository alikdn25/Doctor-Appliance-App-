import { Link } from '@inertiajs/react';
import { Check, Navigation } from 'lucide-react';
import { mapsUrl } from '@/components/customers/types';
import type { TaskItem } from '@/components/tasks/types';
import { taskIcon } from '@/components/tasks/types';
import { useClock } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useTaskDone } from '@/components/tasks/use-task-done';
import { show as showTask } from '@/routes/tasks';

/**
 * A to-do in the Today feed (owner's task of November 25): light yellow with a yellow stripe (job cards have a blue
 * one), "TO DO" on top, a round tick that marks it done without opening it, the type icon (🛒 shop / parts,
 * 🏠 personal, 📞 call), the title, the time or "Anytime", the address with Navigate, and "Carried over" in red. No
 * customer, phone or amount. A tap opens the task's own page.
 */
export function TodoCard({ task }: { task: TaskItem }) {
    const t = useTrans();
    const clock = useClock();

    const done = useTaskDone();
    const toggle = () => done.toggle(task);

    const when =
        task.all_day || !task.start_time
            ? t('tasks.anytime')
            : task.end_time
              ? clock.range(task.start_time, task.end_time)
              : clock.clock(task.start_time);

    return (
        <li
            className={cn(
                'da-card da-todo-card flex items-stretch overflow-hidden border-l-4',
            )}
            data-test="todo-card"
            data-done={task.done || undefined}
        >
            <button
                type="button"
                aria-label={
                    task.done ? t('tasks.mark_not_done') : t('tasks.mark_done')
                }
                aria-pressed={task.done}
                disabled={!task.can_edit}
                onClick={toggle}
                className="flex w-12 shrink-0 items-center justify-center"
                data-test="todo-toggle"
            >
                <span
                    className={cn(
                        'flex size-7 items-center justify-center rounded-full border-2 border-amber-500 bg-white dark:bg-transparent',
                        task.done &&
                            'bg-amber-500 text-white dark:bg-amber-500',
                    )}
                >
                    {task.done && <Check className="size-4" />}
                </span>
            </button>
            <Link
                href={showTask(task.id)}
                className="min-w-0 flex-1 space-y-0.5 py-2.5 pr-2"
                data-test="todo-open"
            >
                <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] font-bold tracking-wide text-amber-800 uppercase dark:text-amber-300">
                    {t('tasks.todo_label')}
                    {(task.carried_days ?? 0) > 0 && !task.done && (
                        <span
                            className="rounded-full bg-red-600 px-1.5 py-px text-[10px] text-white normal-case"
                            data-test="todo-carried"
                        >
                            {t('tasks.carried_over')}
                        </span>
                    )}
                </span>
                <span
                    className={cn(
                        'flex items-center gap-1.5 font-semibold',
                        task.done && 'text-muted-foreground line-through',
                    )}
                >
                    <span aria-hidden="true">{taskIcon(task.kind)}</span>
                    <span className="truncate">{task.title}</span>
                </span>
                <span className="block text-sm font-medium text-muted-foreground">
                    {when}
                </span>
                {task.address && (
                    <span className="block truncate text-sm text-muted-foreground">
                        {task.address}
                    </span>
                )}
            </Link>
            {task.address && (
                <a
                    href={mapsUrl(task.address)}
                    target="_blank"
                    rel="noreferrer"
                    aria-label={t('jobs.navigate')}
                    className="da-press flex w-12 shrink-0 items-center justify-center text-sky-700 dark:text-sky-300"
                    data-test="todo-navigate"
                >
                    <Navigation className="size-5" />
                </a>
            )}
            {done.dialog}
        </li>
    );
}
