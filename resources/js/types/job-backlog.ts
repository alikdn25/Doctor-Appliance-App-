export type BacklogReason =
    | 'overdue'
    | 'parts_to_order'
    | 'estimate_to_send'
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
    'estimate_to_send',
    'needs_schedule',
    'waiting_for_parts',
    'waiting_for_customer',
    'on_hold',
];
