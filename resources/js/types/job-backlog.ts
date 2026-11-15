export type BacklogReason =
    | 'draft'
    | 'overdue'
    | 'needs_schedule'
    | 'need_to_order_parts'
    | 'waiting_for_parts'
    | 'waiting_for_customer'
    | 'on_hold'
    | 'scheduled';

export type JobBacklogSummary = {
    total: number;
    counts: Record<BacklogReason, number>;
};
