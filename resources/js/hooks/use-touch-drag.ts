import type { TouchEvent as ReactTouchEvent } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type DropTarget = {
    lane: number | null;
    date: string;
    /** Minutes from midnight under the finger (day grid), null for whole-day cells (week grid). */
    minutes: number | null;
};

type Ghost = { x: number; y: number; label: string };

type Session<T> = {
    item: T;
    label: string;
    startX: number;
    startY: number;
    x: number;
    y: number;
    active: boolean;
    timer: ReturnType<typeof setTimeout>;
};

const LONG_PRESS_MS = 350;
const MOVE_TOLERANCE = 10;
const EDGE = 60;

/**
 * Drag and drop with a finger: press and hold an item, move it, lift the finger over a
 * drop zone. A quick swipe still scrolls the calendar as usual.
 *
 * Drop zones are elements with data-drop-date and data-drop-lane ("" for unassigned);
 * data-drop-mode="time" zones also need data-hour-start to turn the finger position into a time.
 */
export function useTouchDrag<T>({
    hourPx,
    onStart,
    onDrop,
    onCancel,
}: {
    hourPx: number;
    onStart: (item: T) => void;
    onDrop: (item: T, target: DropTarget) => void;
    onCancel: () => void;
}) {
    const session = useRef<Session<T> | null>(null);
    const suppressClick = useRef(false);
    const [ghost, setGhost] = useState<Ghost | null>(null);
    const handlers = useRef({ onStart, onDrop, onCancel });
    handlers.current = { onStart, onDrop, onCancel };

    const targetAt = useCallback(
        (x: number, y: number): DropTarget | null => {
            const el = document
                .elementFromPoint(x, y)
                ?.closest<HTMLElement>('[data-drop-date]');

            if (!el) {
                return null;
            }

            const { dropLane, dropDate, dropMode, hourStart } = el.dataset;
            const lane = dropLane ? Number(dropLane) : null;
            let minutes: number | null = null;

            if (dropMode === 'time') {
                const rect = el.getBoundingClientRect();
                minutes =
                    Number(hourStart ?? 0) * 60 +
                    ((y - rect.top) / hourPx) * 60;
            }

            return { lane, date: dropDate ?? '', minutes };
        },
        [hourPx],
    );

    useEffect(() => {
        const finish = (e: TouchEvent, cancelled: boolean) => {
            const s = session.current;
            session.current = null;

            if (!s) {
                return;
            }

            clearTimeout(s.timer);

            if (!s.active) {
                return;
            }

            if (e.cancelable) {
                e.preventDefault();
            }

            // The finger lifting would otherwise "click" the item and open its details.
            suppressClick.current = true;
            setTimeout(() => (suppressClick.current = false), 400);
            setGhost(null);

            const target = cancelled ? null : targetAt(s.x, s.y);

            if (target) {
                handlers.current.onDrop(s.item, target);
            } else {
                handlers.current.onCancel();
            }
        };

        const move = (e: TouchEvent) => {
            const s = session.current;
            const touch = e.touches[0];

            if (!s || !touch) {
                return;
            }

            s.x = touch.clientX;
            s.y = touch.clientY;

            if (!s.active) {
                // Moving before the long press means the user is scrolling.
                if (
                    Math.hypot(s.x - s.startX, s.y - s.startY) > MOVE_TOLERANCE
                ) {
                    clearTimeout(s.timer);
                    session.current = null;
                }

                return;
            }

            // While dragging, the page must not scroll under the finger…
            e.preventDefault();
            setGhost({ x: s.x, y: s.y, label: s.label });

            // …except near the edges, to reach other hours and lanes.
            if (s.y > window.innerHeight - EDGE) {
                window.scrollBy(0, 12);
            } else if (s.y < EDGE) {
                window.scrollBy(0, -12);
            }

            const scroller = document
                .elementFromPoint(s.x, s.y)
                ?.closest<HTMLElement>('[data-drag-scroll]');

            if (scroller) {
                const rect = scroller.getBoundingClientRect();

                if (s.x > rect.right - EDGE) {
                    scroller.scrollBy(12, 0);
                } else if (s.x < rect.left + EDGE) {
                    scroller.scrollBy(-12, 0);
                }
            }
        };

        const end = (e: TouchEvent) => finish(e, false);
        const cancel = (e: TouchEvent) => finish(e, true);

        document.addEventListener('touchmove', move, { passive: false });
        document.addEventListener('touchend', end, { passive: false });
        document.addEventListener('touchcancel', cancel);

        return () => {
            document.removeEventListener('touchmove', move);
            document.removeEventListener('touchend', end);
            document.removeEventListener('touchcancel', cancel);
        };
    }, [targetAt]);

    const begin = useCallback(
        (e: ReactTouchEvent<HTMLElement>, item: T, label: string) => {
            const touch = e.touches[0];

            if (!touch || e.touches.length > 1) {
                return;
            }

            const s: Session<T> = {
                item,
                label,
                startX: touch.clientX,
                startY: touch.clientY,
                x: touch.clientX,
                y: touch.clientY,
                active: false,
                timer: setTimeout(() => {
                    s.active = true;
                    navigator.vibrate?.(30);
                    setGhost({ x: s.x, y: s.y, label });
                    handlers.current.onStart(item);
                }, LONG_PRESS_MS),
            };

            if (session.current) {
                clearTimeout(session.current.timer);
            }

            session.current = s;
        },
        [],
    );

    /** True right after a touch drop: skip the click that follows. */
    const consumeClick = useCallback(() => suppressClick.current, []);

    return { begin, ghost, consumeClick };
}
