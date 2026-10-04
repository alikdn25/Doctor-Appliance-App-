/**
 * One palette for job statuses, used by badges, the unfinished-jobs bar and the calendar.
 * The customer is waiting on us: red (parts to order) and crimson (estimate to send).
 * Orange: parts are ordered and on the way. Yellow: we are waiting on the customer. Bronze: on hold.
 */
type StatusColor = { badge: string; dot: string; border: string };

const palette: Record<string, StatusColor> = {
    new: {
        badge: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
        dot: 'bg-sky-600 text-white',
        border: 'border-l-sky-500',
    },
    scheduled: {
        badge: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
        dot: 'bg-indigo-600 text-white',
        border: 'border-l-primary',
    },
    on_the_way: {
        badge: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
        dot: 'bg-violet-600 text-white',
        border: 'border-l-violet-500',
    },
    in_progress: {
        badge: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
        dot: 'bg-blue-600 text-white',
        border: 'border-l-blue-600',
    },
    parts_to_order: {
        badge: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
        dot: 'bg-red-600 text-white',
        border: 'border-l-red-600',
    },
    estimate_to_send: {
        badge: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
        dot: 'bg-rose-600 text-white',
        border: 'border-l-rose-600',
    },
    waiting_for_parts: {
        badge: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
        dot: 'bg-orange-500 text-white',
        border: 'border-l-orange-500',
    },
    waiting_for_customer: {
        badge: 'bg-yellow-100 text-yellow-900 dark:bg-yellow-950 dark:text-yellow-200',
        dot: 'bg-yellow-400 text-yellow-950',
        border: 'border-l-yellow-400',
    },
    on_hold: {
        badge: 'bg-[#f4e4d2] text-[#6b3f17] dark:bg-[#3b2612] dark:text-[#f0c99c]',
        dot: 'bg-[#b87333] text-white',
        border: 'border-l-[#b87333]',
    },
    completed: {
        badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
        dot: 'bg-emerald-600 text-white',
        border: 'border-l-emerald-500',
    },
    invoiced: {
        badge: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
        dot: 'bg-teal-600 text-white',
        border: 'border-l-teal-500',
    },
    paid: {
        badge: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
        dot: 'bg-green-600 text-white',
        border: 'border-l-green-600',
    },
    cancelled: {
        badge: 'bg-zinc-200 text-zinc-700 line-through dark:bg-zinc-800 dark:text-zinc-300',
        dot: 'bg-zinc-500 text-white',
        border: 'border-l-zinc-400',
    },
    // Backlog-only reasons.
    overdue: {
        badge: 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900',
        dot: 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900',
        border: 'border-l-zinc-900',
    },
    needs_schedule: {
        badge: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
        dot: 'bg-sky-600 text-white',
        border: 'border-l-sky-500',
    },
};

const fallback: StatusColor = {
    badge: 'bg-muted text-foreground',
    dot: 'bg-muted-foreground text-white',
    border: 'border-l-muted-foreground/40',
};

export function statusColor(status: string): StatusColor {
    return palette[status] ?? fallback;
}

/** Statuses that tint the whole calendar block, so jobs waiting on us, on parts or on the customer stand out. */
const tinted = [
    'parts_to_order',
    'estimate_to_send',
    'waiting_for_parts',
    'waiting_for_customer',
    'on_hold',
];

/** Classes for a job's block in the calendar: a colored left strip and, while it waits, a tinted card. */
export function calendarBlock(status: string): string {
    const color = statusColor(status);

    return `border-l-4 ${color.border} ${tinted.includes(status) ? color.badge : 'bg-card'}`;
}
