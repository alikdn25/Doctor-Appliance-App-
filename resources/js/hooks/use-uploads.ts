import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { UploadItem } from '@/lib/upload-queue';
import {
    onUploadDone,
    startUploadQueue,
    subscribeUploads,
} from '@/lib/upload-queue';

/**
 * Uploads waiting in the queue whose tag starts with the prefix (e.g. "job:12:").
 * Reloads the given page props when one of them finishes, so the uploaded file shows up.
 */
export function useUploads(prefix: string, reloadOnly: string[] = []) {
    const [items, setItems] = useState<UploadItem[]>([]);

    useEffect(() => {
        startUploadQueue();

        const unsubscribe = subscribeUploads((all) =>
            setItems(all.filter((item) => item.tag.startsWith(prefix))),
        );
        const offDone = onUploadDone((item) => {
            if (item.tag.startsWith(prefix)) {
                router.reload({ only: reloadOnly });
            }
        });

        return () => {
            unsubscribe();
            offDone();
        };
        // reloadOnly is a constant list per page.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [prefix]);

    return items;
}
