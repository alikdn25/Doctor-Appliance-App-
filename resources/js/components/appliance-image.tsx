import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Option } from '@/types';

/** Appliance types that have a picture in public/images/appliances. */
const PICTURED = new Set([
    'refrigerator',
    'freezer',
    'wine_cooler',
    'washer',
    'dryer',
    'washer_dryer_combo',
    'dishwasher',
    'range',
    'oven',
    'cooktop',
    'microwave',
    'range_hood',
    'other',
    'multiple',
    'installation',
]);

export const applianceImageUrl = (type: string) =>
    `/images/appliances/${PICTURED.has(type) ? type : 'other'}.png`;

/** Light stainless-steel picture of an appliance type (docs/DESIGN.md, Imagery). */
export function ApplianceImage({
    type,
    className,
}: {
    type: string;
    className?: string;
}) {
    return (
        <img
            src={applianceImageUrl(type)}
            alt=""
            loading="lazy"
            className={cn(
                'size-12 shrink-0 rounded-xl bg-[#F6F9FD] object-contain p-1 mix-blend-multiply',
                className,
            )}
        />
    );
}

/** Image tiles, three per row, for choosing an appliance type with one tap. */
export function ApplianceTypePicker({
    options,
    value,
    onChange,
    label,
    compact = false,
}: {
    options: Option[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    /** Booking (UX-REDESIGN §7.1): 4 columns, 40 px pictures, 12 px labels, "Other" last. */
    compact?: boolean;
}) {
    const shown = compact
        ? [
              ...options.filter((o) => o.value !== 'other'),
              ...options.filter((o) => o.value === 'other'),
          ]
        : options;

    return (
        <div
            role="group"
            aria-label={label}
            className={
                compact ? 'grid grid-cols-4 gap-1.5' : 'grid grid-cols-3 gap-2'
            }
        >
            {shown.map((o) => {
                const on = o.value === value;
                return (
                    <button
                        key={o.value}
                        type="button"
                        aria-pressed={on}
                        onClick={() => onChange(o.value)}
                        className={
                            compact
                                ? 'da-tile da-press relative flex flex-col items-center gap-0.5 px-1 pt-1.5 pb-1.5'
                                : 'da-tile da-press relative flex flex-col items-center gap-1 px-1.5 pt-2 pb-2.5'
                        }
                    >
                        <img
                            src={applianceImageUrl(o.value)}
                            alt=""
                            loading="lazy"
                            className={
                                compact
                                    ? 'h-10 w-full object-contain mix-blend-multiply'
                                    : 'h-16 w-full object-contain mix-blend-multiply'
                            }
                        />
                        <span
                            className={
                                compact
                                    ? 'line-clamp-2 text-center text-xs leading-tight font-semibold'
                                    : 'flex min-h-8 items-center text-center text-[13px] leading-tight font-bold'
                            }
                        >
                            {o.label}
                        </span>
                        {on && (
                            <span className="da-primary absolute top-1 right-1 flex size-5 items-center justify-center rounded-full">
                                <Check
                                    className="size-3.5"
                                    strokeWidth={3}
                                    aria-hidden="true"
                                />
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
