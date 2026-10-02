import { AlertCircle, CloudUpload, Loader2, RotateCw, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import type { UploadItem } from '@/lib/upload-queue';
import { discardUpload, retryUpload } from '@/lib/upload-queue';

/**
 * State of one queued upload, with Retry / Discard when it failed for good.
 */
export function UploadBadge({ item }: { item: UploadItem }) {
    const t = useTrans();

    if (item.status === 'failed') {
        return (
            <div className="space-y-1 text-xs">
                <div className="flex items-start gap-1 text-destructive">
                    <AlertCircle className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        {t('jobs.uploads.failed')}
                        {item.error && `: ${item.error}`}
                    </span>
                </div>
                <div className="flex gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-8"
                        onClick={() => void retryUpload(item.id)}
                    >
                        <RotateCw /> {t('jobs.uploads.retry')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-8"
                        onClick={() => void discardUpload(item.id)}
                    >
                        <X /> {t('jobs.uploads.discard')}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <span className="flex items-center gap-1 text-xs text-muted-foreground">
            {item.status === 'uploading' ? (
                <Loader2 className="size-3.5 animate-spin" />
            ) : (
                <CloudUpload className="size-3.5" />
            )}
            {item.status === 'uploading'
                ? t('jobs.uploads.uploading')
                : t('jobs.uploads.waiting')}
        </span>
    );
}
