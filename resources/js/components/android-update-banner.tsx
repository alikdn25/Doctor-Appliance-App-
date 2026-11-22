import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import {
    downloadAndroidRelease,
    newerAndroidRelease,
} from '@/lib/native-shell';
import type { AndroidRelease } from '@/lib/native-shell';

// Checked once per start of the app (not on every page).
let checked: Promise<AndroidRelease | null> | null = null;
let dismissed = false;

/**
 * "Update available · Download" in the Android app when the server has a newer APK (owner's task of November 22).
 * Download opens the APK in the phone's browser; Later hides it until the next start. Nothing in a browser.
 */
export function AndroidUpdateBanner() {
    const t = useTrans();
    const [release, setRelease] = useState<AndroidRelease | null>(null);

    useEffect(() => {
        checked ??= newerAndroidRelease();
        let alive = true;
        void checked.then((found) => {
            if (alive && !dismissed) setRelease(found);
        });

        return () => {
            alive = false;
        };
    }, []);

    if (!release) return null;

    return (
        <div
            role="status"
            data-test="android-update"
            className="fixed inset-x-0 bottom-0 z-[60] flex justify-center px-3 pb-[max(5.5rem,calc(env(safe-area-inset-bottom)+5rem))]"
        >
            <div className="da-card flex w-full max-w-md items-center gap-3 p-2 pl-4 shadow-lg">
                <Download className="size-5 shrink-0 text-link" />
                <p className="min-w-0 flex-1 text-sm font-semibold">
                    {t('android.update_available')}
                    <span className="block text-xs font-normal text-muted-foreground">
                        {t('android.version', {
                            version: release.version_name,
                        })}
                    </span>
                </p>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        dismissed = true;
                        setRelease(null);
                    }}
                >
                    {t('android.later')}
                </Button>
                <Button
                    size="sm"
                    onClick={() => downloadAndroidRelease(release.url)}
                    data-test="android-update-download"
                >
                    {t('android.download')}
                </Button>
            </div>
        </div>
    );
}
