import { CloudUpload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTrans } from '@/lib/i18n';
import type { UploadItem } from '@/lib/upload-queue';
import { subscribeUploads } from '@/lib/upload-queue';

/**
 * Tells the technician that photos or signatures are still waiting to upload,
 * so they do not leave the signal-free basement assuming everything was sent.
 */
export function UploadStatusBar() {
    const t = useTrans();
    const [items, setItems] = useState<UploadItem[]>([]);

    useEffect(() => subscribeUploads(setItems), []);

    if (items.length === 0) {
        return null;
    }

    const failed = items.some((i) => i.status === 'failed');

    return (
        <div
            role="status"
            className={
                failed
                    ? 'flex items-center gap-2 bg-destructive/10 px-4 py-2 text-sm text-destructive'
                    : 'flex items-center gap-2 bg-amber-100 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100'
            }
        >
            <CloudUpload className="size-4 shrink-0" />
            {failed
                ? t('jobs.uploads.failed')
                : t('jobs.uploads.queued', { count: items.length })}
        </div>
    );
}
