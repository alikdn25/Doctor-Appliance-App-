export type BacklogReason =
    | 'draft'
    | 'overdue'
    | 'needs_schedule'
    | 'need_to_order_parts'
    | 'waiting_for_parts'
    | 'waiting_for_customer'
    | 'on_hold'
    | 'scheduled';

/** A reason, or "no_address" (jobs booked without an address, in any reason). */
export type BacklogFilter = BacklogReason | 'no_address';

export type JobBacklogSummary = {
    total: number;
    counts: Record<BacklogFilter, number>;
    no_address: number;
};
