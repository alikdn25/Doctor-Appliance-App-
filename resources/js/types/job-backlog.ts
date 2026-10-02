export type BacklogReason =
    | 'overdue'
    | 'needs_schedule'
    | 'waiting_for_parts'
    | 'waiting_for_customer'
    | 'on_hold'
    | 'scheduled';

export type JobBacklogSummary = {
    total: number;
    counts: Record<BacklogReason, number>;
};
