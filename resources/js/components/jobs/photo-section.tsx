import { router } from '@inertiajs/react';
import { Camera, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import { UploadBadge } from '@/components/jobs/upload-badge';
import { Button } from '@/components/ui/button';
import { useUploads } from '@/hooks/use-uploads';
import { useTrans } from '@/lib/i18n';
import { compressImage } from '@/lib/image';
import { enqueueUpload, uuid } from '@/lib/upload-queue';
import { destroy, store } from '@/routes/jobs/photos';
import type { Option } from '@/types';

export type JobPhotoData = {
    id: number;
    kind: string;
    url: string;
    taken_at: string | null;
    user: string | null;
    can_delete: boolean;
};

/**
 * Before/after photos: one tap opens the camera; photos go through the upload queue,
 * so they survive a lost signal and show up here while they wait.
 */
export function PhotoSection({
    jobId,
    visitId,
    photos,
    kinds,
    canAdd,
}: {
    jobId: number;
    visitId: number | null;
    photos: JobPhotoData[];
    kinds: Option[];
    canAdd: boolean;
}) {
    const t = useTrans();
    const pending = useUploads(`job:${jobId}:photo:`, ['job']);
    const inputs = useRef<Record<string, HTMLInputElement | null>>({});

    const previews = useMemo(
        () =>
            pending.map((item) => ({
                item,
                url: URL.createObjectURL(item.blob),
            })),
        [pending],
    );

    useEffect(
        () => () => previews.forEach((p) => URL.revokeObjectURL(p.url)),
        [previews],
    );

    const add = async (kind: string, files: FileList | null) => {
        for (const file of Array.from(files ?? [])) {
            const { blob, filename } = await compressImage(file);
            await enqueueUpload({
                url: store(jobId).url,
                fileField: 'photo',
                fields: {
                    kind,
                    client_uuid: uuid(),
                    taken_at: new Date().toISOString(),
                    ...(visitId ? { visit_id: String(visitId) } : {}),
                },
                blob,
                filename,
                tag: `job:${jobId}:photo:${kind}`,
            });
        }
    };

    const remove = (photo: JobPhotoData) => {
        if (confirm(t('jobs.photos.confirm_delete'))) {
            router.delete(destroy([jobId, photo.id]).url, {
                preserveScroll: true,
                only: ['job'],
            });
        }
    };

    return (
        <section className="space-y-3">
            <h2 className="text-base font-medium">{t('jobs.photos.title')}</h2>

            {canAdd && (
                <div className="grid grid-cols-2 gap-2">
                    {kinds.map((kind) => (
                        <div key={kind.value}>
                            <Button
                                type="button"
                                size="lg"
                                variant="outline"
                                className="h-12 w-full"
                                onClick={() =>
                                    inputs.current[kind.value]?.click()
                                }
                            >
                                <Camera />{' '}
                                {t('jobs.photos.take', { kind: kind.label })}
                            </Button>
                            <input
                                ref={(el) => {
                                    inputs.current[kind.value] = el;
                                }}
                                type="file"
                                accept="image/*"
                                capture="environment"
                                multiple
                                className="hidden"
                                onChange={(e) => {
                                    void add(kind.value, e.target.files);
                                    e.target.value = '';
                                }}
                            />
                        </div>
                    ))}
                </div>
            )}

            {kinds.map((kind) => {
                const saved = photos.filter((p) => p.kind === kind.value);
                const waiting = previews.filter((p) =>
                    p.item.tag.endsWith(`:${kind.value}`),
                );

                if (saved.length === 0 && waiting.length === 0) {
                    return null;
                }

                return (
                    <div key={kind.value} className="space-y-1">
                        <h3 className="text-sm text-muted-foreground">
                            {kind.label}
                        </h3>
                        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            {saved.map((photo) => (
                                <li key={photo.id} className="relative">
                                    <a
                                        href={photo.url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <img
                                            src={photo.url}
                                            alt={kind.label}
                                            loading="lazy"
                                            className="aspect-square w-full rounded-md border object-cover"
                                        />
                                    </a>
                                    {photo.can_delete && (
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="secondary"
                                            className="absolute top-1 right-1 size-8 opacity-90"
                                            aria-label={t('jobs.photos.delete')}
                                            onClick={() => remove(photo)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </li>
                            ))}
                            {waiting.map(({ item, url }) => (
                                <li key={item.id} className="space-y-1">
                                    <img
                                        src={url}
                                        alt={kind.label}
                                        className="aspect-square w-full rounded-md border object-cover opacity-60"
                                    />
                                    <UploadBadge item={item} />
                                </li>
                            ))}
                        </ul>
                    </div>
                );
            })}

            {photos.length === 0 && pending.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('jobs.photos.empty')}
                </p>
            )}
        </section>
    );
}
