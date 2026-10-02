import { PenLine } from 'lucide-react';
import type { PointerEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { UploadBadge } from '@/components/jobs/upload-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useUploads } from '@/hooks/use-uploads';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { enqueueUpload } from '@/lib/upload-queue';
import { store } from '@/routes/jobs/signature';

export type SignatureData = {
    url: string;
    name: string | null;
    signed_at: string | null;
    by: string | null;
} | null;

/**
 * Finger-drawn signature on a canvas, sent as PNG through the upload queue.
 */
function SignaturePad({
    onChange,
    canvasRef,
}: {
    onChange: (empty: boolean) => void;
    canvasRef: React.RefObject<HTMLCanvasElement | null>;
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

export function SignatureSection({
    jobId,
    signature,
    customerName,
    canSign,
}: {
    jobId: number;
    signature: SignatureData;
    customerName: string;
    canSign: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const pending = useUploads(`job:${jobId}:signature`, ['job']);
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(customerName);
    const [empty, setEmpty] = useState(true);
    const [error, setError] = useState<string | undefined>();
    const canvas = useRef<HTMLCanvasElement | null>(null);

    useEffect(() => {
        if (open) {
            setEmpty(true);
            setError(undefined);
            setName(customerName);
        }
    }, [open, customerName]);

    const clear = () => {
        const c = canvas.current;
        c?.getContext('2d')?.clearRect(0, 0, c.width, c.height);
        setEmpty(true);
    };

    const save = () => {
        if (empty || !canvas.current) {
            setError(t('jobs.signature.draw_first'));

            return;
        }

        canvas.current.toBlob((blob) => {
            if (!blob) {
                return;
            }

            void enqueueUpload({
                url: store(jobId).url,
                fileField: 'signature',
                fields: { signer_name: name.trim() || customerName },
                blob,
                filename: 'signature.png',
                tag: `job:${jobId}:signature`,
            });
            setOpen(false);
        }, 'image/png');
    };

    if (!signature && !canSign && pending.length === 0) {
        return null;
    }

    return (
        <section className="space-y-2">
            <h2 className="text-base font-medium">
                {t('jobs.signature.title')}
            </h2>

            {signature ? (
                <div className="space-y-1">
                    <img
                        src={signature.url}
                        alt={t('jobs.signature.title')}
                        className="h-28 w-full max-w-sm rounded-md border bg-white object-contain"
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('jobs.signature.signed', {
                            name: signature.name,
                            date: signature.signed_at
                                ? time.dateTime(signature.signed_at)
                                : '',
                        })}
                    </p>
                </div>
            ) : (
                pending.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('jobs.signature.empty')}
                    </p>
                )
            )}

            {pending.map((item) => (
                <UploadBadge key={item.id} item={item} />
            ))}

            {canSign && (
                <Button
                    type="button"
                    size="lg"
                    variant="outline"
                    className="h-12 w-full sm:w-auto"
                    onClick={() => setOpen(true)}
                >
                    <PenLine />{' '}
                    {signature
                        ? t('jobs.signature.resign')
                        : t('jobs.signature.sign')}
                </Button>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('jobs.signature.title')}</DialogTitle>
                        <DialogDescription>
                            {t('jobs.signature.hint')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <FormField
                            id="signer-name"
                            label={t('jobs.signature.name')}
                        >
                            <Input
                                id="signer-name"
                                value={name}
                                maxLength={100}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </FormField>
                        {open && (
                            <SignaturePad
                                canvasRef={canvas}
                                onChange={(isEmpty) => {
                                    setEmpty(isEmpty);
                                    setError(undefined);
                                }}
                            />
                        )}
                        <InputError message={error} />
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                onClick={clear}
                            >
                                {t('jobs.signature.clear')}
                            </Button>
                            <Button type="button" size="lg" onClick={save}>
                                {t('jobs.signature.save')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </section>
    );
}
