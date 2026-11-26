import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { TaskItem } from '@/components/tasks/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTrans } from '@/lib/i18n';
import { done as doneRoute } from '@/routes/tasks';

/**
 * Marking a to-do done or not done. "Buy at {supplier}: N parts" (owner's task of November 26) first shows its parts,
 * all ticked: the ticked ones go to Waiting, the unticked stay under Order.
 */
export function useTaskDone(options: { preserveState?: boolean } = {}) {
    const t = useTrans();
    const [task, setTask] = useState<TaskItem | null>(null);
    const [picked, setPicked] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);

    const post = (target: TaskItem, data: Record<string, unknown>) =>
        router.post(doneRoute(target.id).url, data as never, {
            preserveScroll: true,
            preserveState: options.preserveState ?? true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setTask(null),
        });

    const toggle = (target: TaskItem) => {
        if (!target.done && target.parts && target.parts.length > 0) {
            setPicked(target.parts.map((part) => part.id));
            setTask(target);

            return;
        }
        post(target, { done: !target.done });
    };

    const dialog = (
        <Dialog
            open={task !== null}
            onOpenChange={(open) => !open && setTask(null)}
        >
            <DialogContent
                className="sm:max-w-md"
                data-test="task-parts-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{task?.title}</DialogTitle>
                    <DialogDescription>
                        {t('tasks.parts_done.hint')}
                    </DialogDescription>
                </DialogHeader>
                <ul className="divide-y">
                    {(task?.parts ?? []).map((part) => (
                        <li key={part.id}>
                            <label className="flex min-h-12 items-center gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={picked.includes(part.id)}
                                    onChange={(e) =>
                                        setPicked((list) =>
                                            e.target.checked
                                                ? [...list, part.id]
                                                : list.filter(
                                                      (id) => id !== part.id,
                                                  ),
                                        )
                                    }
                                    data-test="task-part"
                                />
                                <span className="min-w-0 flex-1">
                                    {part.quantity > 1
                                        ? `${part.quantity} × `
                                        : ''}
                                    {part.name}
                                    {part.job !== null && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · #{part.job}
                                        </span>
                                    )}
                                </span>
                            </label>
                        </li>
                    ))}
                </ul>
                <Button
                    size="lg"
                    className="w-full"
                    loading={busy}
                    disabled={busy}
                    onClick={() =>
                        task && post(task, { done: true, parts: picked })
                    }
                    data-test="task-parts-done"
                >
                    {t('tasks.parts_done.submit')}
                </Button>
            </DialogContent>
        </Dialog>
    );

    return { toggle, dialog };
}
