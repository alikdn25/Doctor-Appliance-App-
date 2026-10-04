export type BacklogReason =
    | 'overdue'
    | 'parts_to_order'
    | 'needs_schedule'
    | 'waiting_for_parts'
    | 'waiting_for_customer'
    | 'on_hold'
    | 'scheduled';

export type JobBacklogSummary = {
    total: number;
    counts: Record<BacklogReason, number>;
};

/** Reasons shown as colored counters in the top bar, most urgent first. Scheduled work is normal and not counted. */
export const attentionReasons: BacklogReason[] = [
    'overdue',
    'parts_to_order',
    'needs_schedule',
    'waiting_for_parts',
    'waiting_for_customer',
    'on_hold',
];

/** One color per reason, the same in the bar and on the Not completed jobs page. */
export const reasonColors: Record<BacklogReason, string> = {
    overdue: 'bg-red-600 text-white',
    parts_to_order: 'bg-amber-400 text-amber-950',
    needs_schedule: 'bg-sky-600 text-white',
    waiting_for_parts: 'bg-purple-600 text-white',
    waiting_for_customer: 'bg-cyan-600 text-white',
    on_hold: 'bg-rose-400 text-rose-950',
    scheduled: 'bg-indigo-600 text-white',
};
