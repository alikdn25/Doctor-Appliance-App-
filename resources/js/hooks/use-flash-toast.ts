import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import { translate } from '@/lib/i18n';
import type { TranslationTree } from '@/lib/i18n';
import type { FlashToast } from '@/types/ui';

/** A save that did not go through: large, red and long enough to read; the form stays open with what was typed. */
const SAVE_FAILED_CLASS =
    '!border-2 !border-red-700 !bg-red-600 !text-base !font-semibold !text-white';

export function useFlashToast(): void {
    // The toaster sits outside the page (no usePage here): the words come with each page.
    const words = useRef<TranslationTree>({});

    useEffect(
        () =>
            router.on('navigate', (event) => {
                const props = (event as CustomEvent).detail?.page?.props;
                if (props?.translations) {
                    words.current = props.translations as TranslationTree;
                }
            }),
        [],
    );

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
        const failed = (key: string, fallback: string) => {
            const text = translate(words.current, key);
            toast.error(text === key ? fallback : text, {
                duration: 15000,
                className: SAVE_FAILED_CLASS,
            });
        };
        const offHttp = router.on('httpException', (event) => {
            const status = (event as CustomEvent).detail?.response?.status ?? 0;

            if (status >= 500 || status === 0) {
                failed(
                    'common.save_failed_server',
                    'Not saved: the server had a problem. Press Save again.',
                );
                event.preventDefault();
            }
        });
        const offNetwork = router.on('networkError', (event) => {
            failed(
                'common.save_failed',
                'Not saved. Check the connection and press Save again.',
            );
            event.preventDefault();
        });

        return () => {
            offHttp();
            offNetwork();
        };
    }, []);
}
