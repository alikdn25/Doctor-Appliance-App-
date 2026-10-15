import type { LucideIcon } from 'lucide-react';
import {
    Banknote,
    CalendarDays,
    Car,
    CircleCheck,
    CircleDashed,
    CircleX,
    Clock,
    FilePlus,
    Package,
    PauseCircle,
    Pencil,
    Receipt,
    RotateCcw,
    Send,
    ThumbsUp,
    UserRound,
    Wrench,
} from 'lucide-react';
import type { CSSProperties } from 'react';

/**
 * One status dictionary for the whole app (UX-REDESIGN §4.3): badges, calendar blocks and filter chips all read
 * their colour and icon here. Colours are CSS tokens (--status-<tone>-from/-to/-text in app.css).
 */
export type StatusTone =
    | 'new'
    | 'scheduled'
    | 'on-the-way'
    | 'in-progress'
    | 'waiting-for-parts'
    | 'waiting-for-customer'
    | 'on-hold'
    | 'completed'
    | 'invoiced'
    | 'paid'
    | 'cancelled'
    | 'draft'
    | 'declined'
    | 'refunded';

type StatusLook = { tone: StatusTone; icon: LucideIcon; strike?: boolean };

const looks: Record<string, StatusLook> = {
    // Jobs and visits.
    new: { tone: 'new', icon: FilePlus },
    scheduled: { tone: 'scheduled', icon: CalendarDays },
    on_the_way: { tone: 'on-the-way', icon: Car },
    in_progress: { tone: 'in-progress', icon: Wrench },
    waiting_for_parts: { tone: 'waiting-for-parts', icon: Package },
    waiting_for_customer: { tone: 'waiting-for-customer', icon: UserRound },
    on_hold: { tone: 'on-hold', icon: PauseCircle },
    completed: { tone: 'completed', icon: CircleCheck },
    done: { tone: 'completed', icon: CircleCheck },
    invoiced: { tone: 'invoiced', icon: Receipt },
    paid: { tone: 'paid', icon: Banknote },
    cancelled: { tone: 'cancelled', icon: CircleX, strike: true },
    // Estimates and invoices.
    draft: { tone: 'draft', icon: Pencil },
    sent: { tone: 'new', icon: Send },
    approved: { tone: 'completed', icon: ThumbsUp },
    declined: { tone: 'declined', icon: CircleX },
    revised: { tone: 'draft', icon: RotateCcw, strike: true },
    unpaid: { tone: 'in-progress', icon: Clock },
    partially_paid: { tone: 'waiting-for-customer', icon: CircleDashed },
    refunded: { tone: 'refunded', icon: RotateCcw },
    partially_refunded: { tone: 'refunded', icon: RotateCcw },
    void: { tone: 'cancelled', icon: CircleX, strike: true },
};

export const statusKeys = Object.keys(looks);

export function statusLook(status: string): StatusLook {
    return looks[status] ?? { tone: 'draft', icon: CircleDashed };
}

/** Inline CSS variables that give an element the status colours (used with the da-status class). */
export function statusStyle(status: string): CSSProperties {
    const { tone } = statusLook(status);

    return {
        '--status-from': `var(--status-${tone}-from)`,
        '--status-to': `var(--status-${tone}-to)`,
        '--status-text': `var(--status-${tone}-text)`,
    } as CSSProperties;
}

/** Cancelled, void and revised records are struck through in lists. */
export function isStruckThrough(status: string): boolean {
    return statusLook(status).strike === true;
}
