import { router } from '@inertiajs/react';
import { Camera, Images, Plus, Trash2, X } from 'lucide-react';
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
    compact = false,
}: {
    jobId: number;
    visitId: number | null;
    photos: JobPhotoData[];
    kinds: Option[];
    canAdd: boolean;
    /** Finish visit mockup: one "Add photo" button and one row of thumbnails with a delete cross. */
    compact?: boolean;
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

    if (compact) {
        // Photos taken while finishing the visit are "after" photos.
        const kind = kinds[kinds.length - 1]?.value ?? 'after';

        return (
            <section className="space-y-3">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="flex items-center gap-2 text-base font-bold">
                        <Images className="size-5" aria-hidden="true" />
                        {t('jobs.photos.title')}
                    </h2>
                    {canAdd && (
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                className="h-10 text-[#0050D0]"
                                onClick={() => inputs.current[kind]?.click()}
                            >
                                <Plus /> {t('jobs.photos.add')}
                            </Button>
                            <input
                                ref={(el) => {
                                    inputs.current[kind] = el;
                                }}
                                type="file"
                                accept="image/*"
                                capture="environment"
                                multiple
                                className="hidden"
                                onChange={(e) => {
                                    void add(kind, e.target.files);
                                    e.target.value = '';
                                }}
                            />
                        </>
                    )}
                </div>
                {photos.length + previews.length > 0 ? (
                    <ul className="grid grid-cols-4 gap-2">
                        {photos.map((photo) => (
                            <li key={photo.id} className="relative">
                                <a
                                    href={photo.url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <img
                                        src={photo.url}
                                        alt={t('jobs.photos.title')}
                                        loading="lazy"
                                        className="aspect-[4/3] w-full rounded-xl object-cover shadow-[0_4px_10px_rgba(16,42,79,.2)]"
                                    />
                                </a>
                                {photo.can_delete && (
                                    <button
                                        type="button"
                                        aria-label={t('jobs.photos.delete')}
                                        onClick={() => remove(photo)}
                                        className="absolute -top-1.5 -right-1.5 flex size-7 items-center justify-center rounded-full border-2 border-white bg-[#0F1B2D] text-white shadow"
                                    >
                                        <X className="size-4" strokeWidth={3} />
                                    </button>
                                )}
                            </li>
                        ))}
                        {previews.map(({ item, url }) => (
                            <li key={item.id} className="space-y-1">
                                <img
                                    src={url}
                                    alt=""
                                    className="aspect-[4/3] w-full rounded-xl object-cover opacity-60"
                                />
                                <UploadBadge item={item} />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('jobs.photos.empty')}
                    </p>
                )}
            </section>
        );
    }

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
