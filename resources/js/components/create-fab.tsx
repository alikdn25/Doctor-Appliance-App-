import { router, usePage } from '@inertiajs/react';
import { Car, ClipboardPlus, ListTodo, Plus, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { TaskSheet } from '@/components/tasks/task-sheet';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { create as createExpense } from '@/routes/expenses';
import { create as bookCustomer } from '@/routes/jobs';
import { index as trips } from '@/routes/trips';

type Item = {
    key: string;
    label: string;
    icon: ReactNode;
    onSelect: () => void;
};

/**
 * The round "+" above the bottom bar on every screen (owner's task, Oct 24), volumetric with the blue glow. A tap
 * opens Job · Expense · Trip · Task, from the bottom up, next to the thumb: Job books a customer (on the day the
 * calendar shows), Expense and Trip open the new expense and the mileage form, Task opens the task sheet here.
 * Screens with their own pinned actions (forms) hide it, like the bottom bar.
 */
export function CreateFab() {
    const page = usePage();
    const { auth } = page.props;
    const t = useTrans();
    const [open, setOpen] = useState(false);
    const [taskOpen, setTaskOpen] = useState(false);
    const can = auth.can ?? {};
    const calendarDay =
        page.component === 'calendar/index' &&
        typeof page.props.date === 'string'
            ? page.props.date
            : null;
    const shownDay =
        calendarDay ??
        (page.component === 'jobs/mine' && typeof page.props.date === 'string'
            ? page.props.date
            : null);

    // Leaving the screen closes the menu.
    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    useEffect(() => {
        if (!open) return;
        const onKey = (event: KeyboardEvent) =>
            event.key === 'Escape' && setOpen(false);
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    if (!auth.company) return null;

    // Listed top to bottom; Job ends up nearest the button.
    const items = (
        [
            can.createTasks && {
                key: 'task',
                label: t('nav.create.task'),
                icon: <ListTodo />,
                onSelect: () => setTaskOpen(true),
            },
            can.viewBusinessExpenses && {
                key: 'trip',
                label: t('nav.create.trip'),
                icon: <Car />,
                // A fresh screen, so the form opens even when Mileage is already open.
                onSelect: () =>
                    router.visit(trips({ query: { manual: 1 } }), {
                        preserveState: false,
                    }),
            },
            can.viewBusinessExpenses && {
                key: 'expense',
                label: t('nav.create.expense'),
                icon: <Wallet />,
                onSelect: () => router.visit(createExpense()),
            },
            can.createJobs && {
                key: 'job',
                label: t('nav.create.job'),
                icon: <ClipboardPlus />,
                onSelect: () =>
                    router.visit(
                        bookCustomer({
                            query: calendarDay
                                ? { book: 1, date: calendarDay }
                                : { book: 1 },
                        }),
                    ),
            },
        ] as (Item | false | undefined)[]
    ).filter((item): item is Item => !!item);

    if (items.length === 0) return null;

    return (
        <>
            {open && (
                <button
                    type="button"
                    aria-label={t('nav.create.close')}
                    className="fixed inset-0 z-40 bg-[#0f1b2d]/40 backdrop-blur-[2px]"
                    onClick={() => setOpen(false)}
                    data-test="create-fab-backdrop"
                />
            )}
            <div className="da-create-fab fixed right-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-50 flex flex-col items-end gap-3 md:bottom-6">
                {open && (
                    <ul
                        id="create-menu"
                        className="flex flex-col items-end gap-2.5"
                        data-test="create-menu"
                    >
                        {items.map((item) => (
                            <li key={item.key}>
                                <button
                                    type="button"
                                    className="da-press da-raised flex h-12 items-center gap-3 rounded-full pr-2 pl-4 text-[15px] font-semibold text-navy"
                                    onClick={() => {
                                        setOpen(false);
                                        item.onSelect();
                                    }}
                                    data-test={`create-${item.key}`}
                                >
                                    {item.label}
                                    <span className="da-soft flex size-9 items-center justify-center rounded-full [&_svg]:size-5">
                                        {item.icon}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <button
                    type="button"
                    aria-label={
                        open ? t('nav.create.close') : t('nav.create.open')
                    }
                    aria-expanded={open}
                    aria-controls="create-menu"
                    onClick={() => setOpen((value) => !value)}
                    data-test="create-fab"
                    className="da-press da-primary da-fab-glow inline-flex size-16 items-center justify-center rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&_svg]:size-8"
                >
                    <Plus
                        className={cn(
                            'transition-transform duration-200',
                            open && 'rotate-45',
                        )}
                    />
                </button>
            </div>
            <TaskSheet
                open={taskOpen}
                onOpenChange={setTaskOpen}
                date={shownDay}
            />
        </>
    );
}
