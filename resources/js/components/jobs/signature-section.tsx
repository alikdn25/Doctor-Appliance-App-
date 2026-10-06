import { PenLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { UploadBadge } from '@/components/jobs/upload-badge';
import { SignaturePad } from '@/components/signature-pad';
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

export function SignatureSection({
    jobId,
    signature,
    customerName,
    canSign,
    reloadProps = ['job'],
}: {
    jobId: number;
    signature: SignatureData;
    customerName: string;
    canSign: boolean;
    /** Page props that hold the signature, reloaded once the upload is done. */
    reloadProps?: string[];
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const pending = useUploads(`job:${jobId}:signature`, reloadProps);
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
