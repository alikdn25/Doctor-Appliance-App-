import { router, usePage } from '@inertiajs/react';
import {
    Car,
    CreditCard,
    ListTodo,
    Plus,
    Sparkles,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { BookingButtons } from '@/components/jobs/booking-buttons';
import { TaskSheet } from '@/components/tasks/task-sheet';
import { hasCardReader } from '@/lib/card-reader';
import { holdKeyboard } from '@/lib/keyboard-handoff';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { create as createCharge } from '@/routes/charges';
import { create as createExpense } from '@/routes/expenses';
import { index as assistantPage } from '@/routes/assistant';
import { index as trips } from '@/routes/trips';

type Item = {
    key: string;
    label: string;
    icon: ReactNode;
    onSelect: () => void;
};

/**
 * The round "+" above the bottom bar on every screen (owner's task, Oct 24), volumetric with the blue glow. A tap
 * opens, from the bottom up next to the thumb, Quick booking and Book customer side by side (Book customer on the day
 * the calendar shows), then Charge reader · Expense · Trip · Task. Expense and Trip open the new expense and the mileage form, Task opens the task sheet here.
 * Assistant (Owner, owner's task of November 23) is the first item from the button, below the booking buttons; it
 * opens the assistant with the cursor in the question field and the keyboard up.
 * Screens with their own pinned actions (forms) hide it, like the bottom bar. Icons and tap areas are 30% larger than
 * the 48 px standard (owner's task of November 23): rows 62 px high, icons 26 px; labels wrap instead of being cut.
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
            // Charge reader (owner's task of November 21): New charge without a booking; in a browser it is called
            // New charge and takes Cash / E-transfer only.
            can.takeCharges && {
                key: 'charge',
                label: t(
                    hasCardReader()
                        ? 'charges.nav.charge_reader'
                        : 'charges.nav.new_charge',
                ),
                icon: <CreditCard />,
                onSelect: () => router.visit(createCharge()),
            },
        ] as (Item | false | undefined)[]
    ).filter((item): item is Item => !!item);
    // A booking starts with two buttons side by side, nearest the "+": Quick booking and Book customer.
    const booking = can.quickBooking === true || can.createJobs === true;
    const assistant = can.useAssistant === true;

    if (items.length === 0 && !booking && !assistant) return null;

    const itemButton = (item: Item) => (
        <button
            type="button"
            className="da-press da-raised flex h-[3.9rem] max-w-[calc(100vw-2rem)] items-center gap-3 rounded-full pr-2 pl-5 text-left text-[15px] leading-tight font-semibold text-navy"
            onClick={() => {
                setOpen(false);
                item.onSelect();
            }}
            data-test={`create-${item.key}`}
        >
            <span className="min-w-0">{item.label}</span>
            <span className="da-soft flex size-[2.925rem] shrink-0 items-center justify-center rounded-full [&_svg]:size-[1.625rem]">
                {item.icon}
            </span>
        </button>
    );

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
                            <li key={item.key}>{itemButton(item)}</li>
                        ))}
                        {booking && (
                            <li className="w-[min(22rem,calc(100vw-2rem))]">
                                <BookingButtons
                                    date={calendarDay}
                                    onPick={() => setOpen(false)}
                                />
                            </li>
                        )}
                        {assistant && (
                            <li>
                                {itemButton({
                                    key: 'assistant',
                                    label: t('nav.create.assistant'),
                                    icon: <Sparkles />,
                                    onSelect: () => {
                                        // During the tap, so the phone opens the keyboard (see keyboard-handoff).
                                        holdKeyboard();
                                        router.visit(
                                            assistantPage({
                                                query: { focus: 1 },
                                            }),
                                        );
                                    },
                                })}
                            </li>
                        )}
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
