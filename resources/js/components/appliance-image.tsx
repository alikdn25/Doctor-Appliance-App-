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
}: {
    options: Option[];
    value: string;
    onChange: (value: string) => void;
    label: string;
}) {
    return (
        <div role="group" aria-label={label} className="grid grid-cols-3 gap-2">
            {options.map((o) => {
                const on = o.value === value;
                return (
                    <button
                        key={o.value}
                        type="button"
                        aria-pressed={on}
                        onClick={() => onChange(o.value)}
                        className="da-tile da-press relative flex flex-col items-center gap-1 px-1.5 pt-2 pb-2.5"
                    >
                        <img
                            src={applianceImageUrl(o.value)}
                            alt=""
                            loading="lazy"
                            className="h-16 w-full object-contain mix-blend-multiply"
                        />
                        <span className="flex min-h-8 items-center text-center text-[13px] leading-tight font-bold">
                            {o.label}
                        </span>
                        {on && (
                            <span className="da-primary absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full">
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
