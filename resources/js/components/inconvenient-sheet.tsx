import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { toast } from '@/lib/toast';
import { currentScreen, recentActions } from '@/lib/usage';

/**
 * "Inconvenient" (every role): what was hard on this screen, typed or dictated, sent with the screen and the
 * person's last 20 actions. The Owner sees it in the next App review.
 */
export function InconvenientSheet({
    open,
    onOpenChange,
    screen,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The screen the person was on before opening the menu. */
    screen: string | null;
}) {
    const t = useTrans();
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);

    const send = () => {
        if (text.trim() === '' || sending) return;
        setSending(true);
        fetch('/usage/inconvenient', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                text,
                screen: screen ?? currentScreen(),
                recent: recentActions(),
            }),
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((body: { message: string }) => {
                toast.success(body.message);
                setText('');
                onOpenChange(false);
            })
            .catch(() => toast.error(t('usage.inconvenient.failed')))
            .finally(() => setSending(false));
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[85dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="inconvenient-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{t('usage.inconvenient.title')}</SheetTitle>
                    <SheetDescription>
                        {t('usage.inconvenient.hint')}
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-3">
                    <Textarea
                        aria-label={t('usage.inconvenient.title')}
                        rows={4}
                        maxLength={2000}
                        autoFocus
                        placeholder={t('usage.inconvenient.placeholder')}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        data-test="inconvenient-text"
                    />
                    <Button
                        type="button"
                        size="lg"
                        className="w-full"
                        disabled={text.trim() === ''}
                        loading={sending}
                        onClick={send}
                        data-test="inconvenient-send"
                    >
                        {t('usage.inconvenient.send')}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
