import { ArrowDown, ArrowUp, Camera, Check, Sparkles, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { sendJson, xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { compressImage } from '@/lib/image';
import { cn } from '@/lib/utils';

export type ReportPhotoItem = {
    job_photo_id: number;
    caption: string;
    url: string;
};

export type JobPhotoOption = { id: number; url: string; kind: string };

/**
 * The report's text (owner's task of November 25): the app's microphone, "Translate to English" and "Write with AI"
 * (the job's notes and the text in the field → a clean English report). The result goes into the field; the person
 * edits it and saves.
 */
export function ReportText({
    id,
    value,
    onChange,
    jobId,
    rows = 8,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    jobId: number | null;
    rows?: number;
}) {
    const t = useTrans();
    const [writing, setWriting] = useState(false);
    const [failed, setFailed] = useState(false);

    const write = async () => {
        setWriting(true);
        setFailed(false);
        try {
            const response = await sendJson('/ai/report', 'POST', {
                text: value,
                job_id: jobId,
            });
            const data = (await response.json()) as { text?: string };
            if (!response.ok || typeof data.text !== 'string') {
                throw new Error('failed');
            }
            onChange(data.text);
        } catch {
            setFailed(true);
        } finally {
            setWriting(false);
        }
    };

    return (
        <div className="space-y-1">
            <Textarea
                id={id}
                rows={rows}
                value={value}
                translatable
                placeholder={t('service_reports.text_placeholder')}
                onChange={(e) => onChange(e.target.value)}
                data-test="report-text"
                actions={
                    <button
                        type="button"
                        className="da-soft da-press inline-flex h-11 items-center gap-1.5 rounded-xl px-3 text-sm font-semibold text-navy disabled:opacity-50"
                        disabled={writing}
                        onClick={write}
                        data-test="report-ai-write"
                    >
                        <Sparkles className="size-4" />{' '}
                        {writing
                            ? t('ui.ai_write.working')
                            : t('ui.ai_write.button')}
                    </button>
                }
            />
            {failed && (
                <p
                    role="alert"
                    className="text-sm font-semibold text-red-600"
                    data-test="report-ai-error"
                >
                    {t('ui.ai_write.failed')}
                </p>
            )}
        </div>
    );
}

/**
 * The report's photos: "+ Photo" (camera or gallery, several at once; shrunk on the phone and saved among the job's
 * photos too), the job's photos with ticks, a caption under each, remove and move up / down.
 */
export function ReportPhotos({
    jobId,
    value,
    onChange,
    jobPhotos,
    onJobPhotosChange,
}: {
    jobId: number;
    value: ReportPhotoItem[];
    onChange: (photos: ReportPhotoItem[]) => void;
    jobPhotos: JobPhotoOption[];
    onJobPhotosChange: (photos: JobPhotoOption[]) => void;
}) {
    const t = useTrans();
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [failed, setFailed] = useState(false);
    const latest = useRef(value);
    latest.current = value;

    const picked = new Set(value.map((p) => p.job_photo_id));

    const upload = async (files: FileList | null) => {
        if (!files || files.length === 0) return;
        setUploading(true);
        setFailed(false);
        try {
            const body = new FormData();
            for (const file of Array.from(files)) {
                const { blob, filename } = await compressImage(
                    file,
                    1600,
                    0.82,
                );
                body.append('photos[]', blob, filename);
            }
            const response = await fetch(`/jobs/${jobId}/report-photos`, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });
            const data = (await response.json()) as {
                photos?: { id: number; url: string }[];
            };
            if (!response.ok || !data.photos) throw new Error('failed');
            onJobPhotosChange([
                ...jobPhotos,
                ...data.photos.map((p) => ({ ...p, kind: 'after' })),
            ]);
            onChange([
                ...latest.current,
                ...data.photos.map((p) => ({
                    job_photo_id: p.id,
                    caption: '',
                    url: p.url,
                })),
            ]);
        } catch {
            setFailed(true);
        } finally {
            setUploading(false);
            if (input.current) input.current.value = '';
        }
    };

    const toggle = (photo: JobPhotoOption) =>
        onChange(
            picked.has(photo.id)
                ? value.filter((p) => p.job_photo_id !== photo.id)
                : [
                      ...value,
                      { job_photo_id: photo.id, caption: '', url: photo.url },
                  ],
        );

    const move = (index: number, by: number) => {
        const next = [...value];
        const [item] = next.splice(index, 1);
        next.splice(index + by, 0, item);
        onChange(next);
    };

    return (
        <div className="space-y-3" data-test="report-photos">
            <input
                ref={input}
                type="file"
                accept="image/*"
                multiple
                className="sr-only"
                onChange={(e) => upload(e.target.files)}
                data-test="report-photo-input"
            />
            <Button
                type="button"
                variant="outline"
                className="min-h-12 w-full"
                loading={uploading}
                onClick={() => input.current?.click()}
                data-test="report-add-photo"
            >
                <Camera />{' '}
                {uploading
                    ? t('service_reports.uploading')
                    : t('service_reports.add_photo')}
            </Button>
            {failed && (
                <p role="alert" className="text-sm font-semibold text-red-600">
                    {t('service_reports.upload_failed')}
                </p>
            )}

            {value.length > 0 && (
                <ol className="space-y-3">
                    {value.map((photo, index) => (
                        <li
                            key={photo.job_photo_id}
                            className="flex gap-3 rounded-2xl border p-2"
                            data-test="report-photo"
                        >
                            <img
                                src={photo.url}
                                alt=""
                                className="size-24 shrink-0 rounded-xl object-cover"
                            />
                            <div className="min-w-0 flex-1 space-y-2">
                                <Input
                                    aria-label={t('service_reports.caption')}
                                    placeholder={t('service_reports.caption')}
                                    value={photo.caption}
                                    onChange={(e) =>
                                        onChange(
                                            value.map((p, i) =>
                                                i === index
                                                    ? {
                                                          ...p,
                                                          caption:
                                                              e.target.value,
                                                      }
                                                    : p,
                                            ),
                                        )
                                    }
                                    data-test="report-photo-caption"
                                />
                                <div className="flex gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-10"
                                        disabled={index === 0}
                                        aria-label={t(
                                            'service_reports.move_up',
                                        )}
                                        onClick={() => move(index, -1)}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-10"
                                        disabled={index === value.length - 1}
                                        aria-label={t(
                                            'service_reports.move_down',
                                        )}
                                        onClick={() => move(index, 1)}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="ml-auto size-10"
                                        aria-label={t(
                                            'service_reports.remove_photo',
                                        )}
                                        onClick={() =>
                                            onChange(
                                                value.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                        data-test="report-photo-remove"
                                    >
                                        <X />
                                    </Button>
                                </div>
                            </div>
                        </li>
                    ))}
                </ol>
            )}

            {jobPhotos.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        {t('service_reports.job_photos')}
                    </p>
                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        {jobPhotos.map((photo) => {
                            const on = picked.has(photo.id);

                            return (
                                <button
                                    key={photo.id}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => toggle(photo)}
                                    className={cn(
                                        'relative aspect-square overflow-hidden rounded-xl border-2',
                                        on
                                            ? 'border-primary'
                                            : 'border-transparent',
                                    )}
                                    data-test="report-job-photo"
                                >
                                    <img
                                        src={photo.url}
                                        alt=""
                                        loading="lazy"
                                        className="size-full object-cover"
                                    />
                                    <span
                                        className={cn(
                                            'absolute top-1 right-1 inline-flex size-6 items-center justify-center rounded-full border-2 border-white',
                                            on
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-black/30',
                                        )}
                                    >
                                        {on && <Check className="size-4" />}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}
