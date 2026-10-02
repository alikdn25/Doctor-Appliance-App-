import type { PointerEvent, RefObject } from 'react';
import { useEffect, useRef } from 'react';

/**
 * Finger-drawn signature on a canvas (white background, dark ink). The parent reads the PNG from the canvas ref.
 */
export function SignaturePad({
    onChange,
    canvasRef,
}: {
    onChange: (empty: boolean) => void;
    canvasRef: RefObject<HTMLCanvasElement | null>;
}) {
    const drawing = useRef(false);
    const last = useRef<{ x: number; y: number } | null>(null);

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        // Sharp lines on high-density phone screens.
        const ratio = window.devicePixelRatio || 1;
        // offsetWidth ignores the dialog's opening animation (a CSS transform).
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        const ctx = canvas.getContext('2d');

        if (ctx) {
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#111827';
        }
    }, [canvasRef]);

    const point = (e: PointerEvent<HTMLCanvasElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();

        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    };

    const down = (e: PointerEvent<HTMLCanvasElement>) => {
        e.currentTarget.setPointerCapture(e.pointerId);
        drawing.current = true;
        last.current = point(e);
        const ctx = e.currentTarget.getContext('2d');
        ctx?.beginPath();
        ctx?.arc(last.current.x, last.current.y, 1, 0, Math.PI * 2);
        ctx?.fill();
        onChange(false);
    };

    const move = (e: PointerEvent<HTMLCanvasElement>) => {
        if (!drawing.current || !last.current) {
            return;
        }

        const ctx = e.currentTarget.getContext('2d');
        const p = point(e);
        ctx?.beginPath();
        ctx?.moveTo(last.current.x, last.current.y);
        ctx?.lineTo(p.x, p.y);
        ctx?.stroke();
        last.current = p;
    };

    const up = () => {
        drawing.current = false;
        last.current = null;
    };

    return (
        <canvas
            ref={canvasRef}
            className="h-48 w-full touch-none rounded-md border bg-white"
            onPointerDown={down}
            onPointerMove={move}
            onPointerUp={up}
            onPointerCancel={up}
        />
    );
}
