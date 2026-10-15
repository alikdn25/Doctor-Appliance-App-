import { cn } from '@/lib/utils';

export type FilterChip = {
    value: string;
    label: string;
    count?: number;
    /** Red edge and text while not selected (Overdue). */
    danger?: boolean;
};

/**
 * One-line filter chips that scroll sideways on a phone. Single choice by default; multiple when `multiple`.
 * Chips with a count of 0 are shown greyed.
 */
export function FilterChips({
    chips,
    selected,
    onChange,
    label,
    className,
}: {
    chips: FilterChip[];
    selected: string[];
    onChange: (value: string) => void;
    label: string;
    className?: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            data-slot="filter-chips"
            className={cn(
                '-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] md:mx-0 md:flex-wrap md:px-0 [&::-webkit-scrollbar]:hidden',
                className,
            )}
        >
            {chips.map((chip) => {
                const on = selected.includes(chip.value);

                return (
                    <button
                        key={chip.value}
                        type="button"
                        aria-pressed={on}
                        onClick={() => onChange(chip.value)}
                        className={cn(
                            'da-chip da-press inline-flex h-10 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-sm font-medium whitespace-nowrap',
                            chip.danger && 'da-chip-danger',
                            chip.count === 0 && !on && 'opacity-60',
                        )}
                    >
                        {chip.label}
                        {chip.count !== undefined && (
                            <span className="tabular-nums opacity-80">
                                {chip.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
