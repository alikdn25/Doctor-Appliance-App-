import { Camera } from 'lucide-react';
import { useRef } from 'react';
import { UploadBadge } from '@/components/jobs/upload-badge';
import { Button } from '@/components/ui/button';
import { useUploads } from '@/hooks/use-uploads';
import { useTrans } from '@/lib/i18n';
import { compressImage } from '@/lib/image';
import { enqueueUpload } from '@/lib/upload-queue';
import { ratingPlate } from '@/routes/jobs/appliances';

/**
 * Photo of an appliance's rating plate, taken from the job. Kept sharper than job photos
 * so model and serial numbers stay readable.
 */
export function RatingPlateButton({
    jobId,
    applianceId,
    url,
}: {
    jobId: number;
    applianceId: number;
    url: string | null;
}) {
    const t = useTrans();
    const input = useRef<HTMLInputElement>(null);
    const pending = useUploads(`job:${jobId}:plate:${applianceId}`, ['job']);

    const take = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        const { blob, filename } = await compressImage(file, 2400, 0.9);
        await enqueueUpload({
            url: ratingPlate([jobId, applianceId]).url,
            fileField: 'rating_plate',
            fields: {},
            blob,
            filename,
            tag: `job:${jobId}:plate:${applianceId}`,
        });
    };

    return (
        <div className="flex flex-col items-end gap-1">
            <div className="flex items-center gap-1">
                {url && (
                    <a href={url} target="_blank" rel="noreferrer">
                        <img
                            src={url}
                            alt={t('appliances.rating_plate')}
                            className="size-10 rounded border object-cover"
                        />
                    </a>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-10"
                    aria-label={
                        url
                            ? t('jobs.rating_plate.retake')
                            : t('jobs.rating_plate.take')
                    }
                    onClick={() => input.current?.click()}
                >
                    <Camera />
                </Button>
                <input
                    ref={input}
                    type="file"
                    accept="image/*"
                    capture="environment"
                    className="hidden"
                    onChange={(e) => {
                        void take(e.target.files?.[0]);
                        e.target.value = '';
                    }}
                />
            </div>
            {pending.map((item) => (
                <UploadBadge key={item.id} item={item} />
            ))}
        </div>
    );
}
