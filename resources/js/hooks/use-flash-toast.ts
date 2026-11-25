import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import { translate } from '@/lib/i18n';
import type { TranslationTree } from '@/lib/i18n';
import type { FlashToast } from '@/types/ui';

/** A save that did not go through: large, red and long enough to read; the form stays open with what was typed. */
const SAVE_FAILED_CLASS =
    '!border-2 !border-red-700 !bg-red-600 !text-base !font-semibold !text-white';

export function useFlashToast(): void {
    const { translations } = usePage().props;
    const words = useRef(translations as TranslationTree);
    words.current = translations as TranslationTree;

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast[data.type](data.message, {
                ...(data.description ? { description: data.description } : {}),
                ...(data.action
                    ? {
                          duration: 8000,
                          action: {
                              label: data.action.label,
                              onClick: () =>
                                  router.visit(data.action!.url, {
                                      method: data.action!.method ?? 'get',
                                  }),
                          },
                      }
                    : {}),
            });
        });
    }, []);

    // A save that fails on the server or for lack of network (owner's task of November 25): never a silent close.
    // The error page is not shown over the form; the form keeps what was typed (its onSuccess never runs).
    useEffect(() => {
        const failed = (key: string) =>
            toast.error(translate(words.current, key), {
                duration: 15000,
                className: SAVE_FAILED_CLASS,
            });
        const offHttp = router.on('httpException', (event) => {
            const status = (event as CustomEvent).detail?.response?.status ?? 0;

            if (status >= 500 || status === 0) {
                failed('common.save_failed_server');
                event.preventDefault();
            }
        });
        const offNetwork = router.on('networkError', (event) => {
            failed('common.save_failed');
            event.preventDefault();
        });

        return () => {
            offHttp();
            offNetwork();
        };
    }, []);
}
