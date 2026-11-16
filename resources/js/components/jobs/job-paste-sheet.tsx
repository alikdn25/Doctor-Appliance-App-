import { router } from '@inertiajs/react';
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
import { useTrans } from '@/lib/i18n';
import { fillFromMessage } from '@/routes/jobs';

/**
 * "Paste from message" on the job page: the text fills only the job's empty fields (name, phone, email, address,
 * problem); nothing already filled in changes. The text is not kept after the sheet closes.
 */
export function JobPasteSheet({
    open,
    onOpenChange,
    jobId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
}) {
    const t = useTrans();
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const close = (next: boolean) => {
        if (!next) setText('');
        onOpenChange(next);
    };

    return (
        <Sheet open={open} onOpenChange={close}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[85dvh] max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="job-paste-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{t('jobs.quick.paste_title')}</SheetTitle>
                    <SheetDescription>{t('jobs.fill.hint')}</SheetDescription>
                </SheetHeader>
                <Textarea
                    aria-label={t('jobs.quick.paste_title')}
                    rows={6}
                    maxLength={5000}
                    className="min-h-36"
                    value={text}
                    onChange={(event) => setText(event.target.value)}
                />
                <Button
                    type="button"
                    size="lg"
                    disabled={text.trim() === ''}
                    loading={busy}
                    data-test="job-paste-fill"
                    onClick={() => {
                        setBusy(true);
                        router.post(
                            fillFromMessage(jobId).url,
                            { text },
                            {
                                preserveScroll: true,
                                onSuccess: () => close(false),
                                onFinish: () => setBusy(false),
                            },
                        );
                    }}
                >
                    {t('jobs.quick.paste_fill')}
                </Button>
            </SheetContent>
        </Sheet>
    );
}
