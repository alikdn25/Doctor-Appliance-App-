import { usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    CHECK_EVERY_MS,
    fetchServerVersion,
    hasUnsavedForms,
    onUnsavedFormsChange,
    updateApp,
    watchUnsavedForms,
} from '@/lib/app-update';
import { useTrans } from '@/lib/i18n';

/** The version this copy of the app was loaded with (the first page's). */
let loadedVersion: string | undefined;

/**
 * "New version available · Update" after a deploy (owner's task, October 24). It waits while a form with changes
 * is open, so nothing typed is lost.
 */
export function AppUpdateBanner() {
    const t = useTrans();
    const { appVersion } = usePage().props;
    loadedVersion ??= appVersion;
    const [available, setAvailable] = useState(false);
    const [unsaved, setUnsaved] = useState(false);
    const [updating, setUpdating] = useState(false);
    const checking = useRef(false);

    useEffect(() => {
        watchUnsavedForms();
        const check = async () => {
            if (checking.current || !loadedVersion) return;
            checking.current = true;
            const version = await fetchServerVersion();
            checking.current = false;
            if (version && version !== loadedVersion) setAvailable(true);
        };
        const onVisible = () => {
            if (document.visibilityState === 'visible') void check();
        };
        void check();
        const timer = window.setInterval(() => void check(), CHECK_EVERY_MS);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
        };
    }, []);

    // While the banner waits, notice a sheet or form being closed.
    useEffect(() => {
        if (!available) return;
        const sync = () => setUnsaved(hasUnsavedForms());
        sync();
        const off = onUnsavedFormsChange(sync);
        const timer = window.setInterval(sync, 1000);

        return () => {
            off();
            window.clearInterval(timer);
        };
    }, [available]);

    if (!available || unsaved) return null;

    return (
        <div
            role="status"
            data-test="app-update"
            className="fixed inset-x-0 top-0 z-[60] flex justify-center px-3 pt-[max(0.5rem,env(safe-area-inset-top))]"
        >
            <div className="da-card flex w-full max-w-md items-center gap-3 p-2 pl-4 shadow-lg">
                <RefreshCw className="size-5 shrink-0 text-link" />
                <p className="min-w-0 flex-1 text-sm font-semibold">
                    {t('pwa.new_version')}
                </p>
                <Button
                    size="sm"
                    loading={updating}
                    onClick={() => {
                        setUpdating(true);
                        void updateApp();
                    }}
                    data-test="app-update-button"
                >
                    {t('pwa.update')}
                </Button>
            </div>
        </div>
    );
}
