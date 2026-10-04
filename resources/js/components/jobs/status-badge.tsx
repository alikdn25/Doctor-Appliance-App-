import type { LucideIcon } from 'lucide-react';
import {
    Banknote,
    CalendarDays,
    Car,
    CircleCheck,
    CircleX,
    Clock,
    FilePlus,
    Package,
    PauseCircle,
    Receipt,
    Wrench,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// Every status = colour + icon + text (docs/DESIGN.md, "Statuses").
const tones: Record<string, [string, LucideIcon]> = {
    new: [
        'bg-[linear-gradient(90deg,#F3FAFF,#B7E0FF)] text-[#075985]',
        FilePlus,
    ],
    scheduled: [
        'bg-[linear-gradient(90deg,#F3F5FF,#B7C6FF)] text-[#3730A3]',
        CalendarDays,
    ],
    on_the_way: [
        'bg-[linear-gradient(90deg,#FFFBE9,#FFEC9F)] text-[#92400E]',
        Car,
    ],
    in_progress: [
        'bg-[linear-gradient(90deg,#FFF8EE,#FFDCAC)] text-[#9A3412]',
        Wrench,
    ],
    waiting_for_parts: [
        'bg-[linear-gradient(90deg,#FBF6FF,#DFC0FF)] text-[#6B21A8]',
        Package,
    ],
    waiting_for_customer: [
        'bg-[linear-gradient(90deg,#EBFDFF,#A5F7FF)] text-[#155E75]',
        Clock,
    ],
    completed: [
        'bg-[linear-gradient(90deg,#ECFDF4,#A3FFCF)] text-[#065F46]',
        CircleCheck,
    ],
    done: [
        'bg-[linear-gradient(90deg,#ECFDF4,#A3FFCF)] text-[#065F46]',
        CircleCheck,
    ],
    invoiced: [
        'bg-[linear-gradient(90deg,#EBFDFA,#9FFFED)] text-[#115E59]',
        Receipt,
    ],
    paid: [
        'bg-[linear-gradient(90deg,#F1FEF5,#B0FFCB)] text-[#166534]',
        Banknote,
    ],
    cancelled: [
        'bg-[linear-gradient(90deg,#F4F4F5,#CCCCD6)] text-[#3F3F46] line-through',
        CircleX,
    ],
    on_hold: [
        'bg-[linear-gradient(90deg,#FFF4F5,#FFBCC1)] text-[#9F1239]',
        PauseCircle,
    ],
};

export function StatusBadge({
    status,
    label,
    className,
}: {
    status: string;
    label: string;
    className?: string;
}) {
    const [tone, Icon] = tones[status] ?? [
        'bg-[linear-gradient(90deg,#FAFBFC,#D1DCF0)] text-[#334155]',
        null,
    ];

    return (
        <span
            className={cn(
                'da-badge inline-flex w-fit shrink-0 items-center gap-1 rounded-full py-0.5 pr-2.5 pl-2 text-xs font-semibold whitespace-nowrap',
                tone,
                className,
            )}
        >
            {Icon && <Icon className="size-3.5" aria-hidden="true" />}
            {label}
        </span>
    );
}
