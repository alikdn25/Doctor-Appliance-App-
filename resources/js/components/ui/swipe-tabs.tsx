import type { ReactNode, TouchEvent } from 'react';
import { useId, useRef } from 'react';
import { cn } from '@/lib/utils';

export type SwipeTab<T extends string> = {
    value: T;
    label: string;
    /** A small count next to the label, e.g. the number of files. */
    count?: number;
    content: ReactNode;
};

const SWIPE_MIN = 60;

/** A swipe that starts in something scrolling sideways (chips, tables) or in a field belongs to it. */
function ownsSwipe(target: EventTarget | null, root: HTMLElement): boolean {
    let node = target instanceof HTMLElement ? target : null;
    while (node && node !== root) {
        if (
            node.matches('input, textarea, select, [contenteditable], [data-no-swipe]') ||
            (node.scrollWidth > node.clientWidth + 1 &&
                ['auto', 'scroll'].includes(getComputedStyle(node).overflowX))
        ) {
            return true;
        }
        node = node.parentElement;
    }

    return false;
}

/**
 * Tabs that switch on a tap and with a swipe left or right over the content (job page, customer card). The tab
 * bar is a sunken track with the chosen tab raised, as the segmented control.
 */
export function SwipeTabs<T extends string>({
    tabs,
    value,
    onChange,
    label,
    className,
}: {
    tabs: SwipeTab<T>[];
    value: T;
    onChange: (value: T) => void;
    label: string;
    className?: string;
}) {
    const id = useId();
    const start = useRef<{ x: number; y: number } | null>(null);
    const panel = useRef<HTMLDivElement>(null);
    const index = Math.max(
        0,
        tabs.findIndex((tab) => tab.value === value),
    );

    const onTouchStart = (event: TouchEvent) => {
        const touch = event.touches[0];
        start.current =
            event.touches.length === 1 &&
            panel.current &&
            !ownsSwipe(event.target, panel.current)
                ? { x: touch.clientX, y: touch.clientY }
                : null;
    };
    const onTouchEnd = (event: TouchEvent) => {
        const from = start.current;
        start.current = null;
        if (!from) return;
        const touch = event.changedTouches[0];
        const dx = touch.clientX - from.x;
        const dy = touch.clientY - from.y;
        if (Math.abs(dx) < SWIPE_MIN || Math.abs(dx) < Math.abs(dy) * 1.5) {
            return;
        }
        const next = tabs[index + (dx < 0 ? 1 : -1)];
        if (next) onChange(next.value);
    };

    return (
        <div className={cn('space-y-4', className)} data-slot="swipe-tabs">
            <div
                role="tablist"
                aria-label={label}
                className="da-track sticky top-[var(--app-sticky-top,0px)] z-10 flex gap-1 p-1"
            >
                {tabs.map((tab) => {
                    const on = tab.value === value;

                    return (
                        <button
                            key={tab.value}
                            id={`${id}-tab-${tab.value}`}
                            type="button"
                            role="tab"
                            aria-selected={on}
                            aria-controls={`${id}-panel`}
                            onClick={() => onChange(tab.value)}
                            className={cn(
                                'da-press min-h-11 min-w-0 flex-1 truncate rounded-[11px] px-2 text-sm font-semibold',
                                // Five tabs share the width by their labels (Job is short, Messages long), so none is
                                // cut on a 360 px phone.
                                tabs.length >= 5 &&
                                    'flex-auto px-1 text-xs sm:px-2 sm:text-sm',
                                on ? 'da-raised text-foreground' : 'text-[#3d4b60]',
                            )}
                        >
                            {tab.label}
                            {tab.count !== undefined && tab.count > 0 && (
                                <span className="ml-1 text-xs font-medium text-muted-foreground">
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
            <div
                ref={panel}
                id={`${id}-panel`}
                role="tabpanel"
                aria-labelledby={`${id}-tab-${tabs[index]?.value}`}
                className="min-h-40 touch-pan-y"
                onTouchStart={onTouchStart}
                onTouchEnd={onTouchEnd}
            >
                {tabs[index]?.content}
            </div>
        </div>
    );
}
