import { cn } from '@/lib/utils';

/** Two to four options in a sunken track; the chosen one is a raised white pill (Day / Week / Map, Field / Office). */
export function SegmentedControl<T extends string>({
    options,
    value,
    onChange,
    label,
    className,
}: {
    options: { value: T; label: string }[];
    value: T;
    onChange: (value: T) => void;
    label: string;
    className?: string;
}) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            data-slot="segmented-control"
            className={cn('da-track inline-flex gap-1 p-1', className)}
        >
            {options.map((option) => {
                const on = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'da-press min-h-10 flex-1 rounded-[11px] px-4 text-sm font-semibold whitespace-nowrap',
                            on ? 'da-raised text-foreground' : 'text-[#3d4b60]',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
