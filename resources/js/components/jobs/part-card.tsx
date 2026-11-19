import { router } from '@inertiajs/react';
import { PackageCheck, Plus, Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AddPartSheet } from '@/components/parts/add-part-sheet';
import { PartRow } from '@/components/parts/part-row';
import type { PartItem, SupplierOption } from '@/components/parts/types';
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
import { partArrived } from '@/routes/jobs';
import { send as sendArrived } from '@/routes/jobs/part-arrived';

/**
 * The parts of a job (owner's task of November 19): each part with its number ("check" for the AI's), quantity,
 * supplier and Bought / Ordered / Arrived, and "Part needed" to add one. When the parts are on hand (or the job waits
 * for them) the office has "Text the customer": the three nearest free windows, sent only after Send.
 */
export function PartCard({
    jobId,
    parts,
    suppliers,
    canAdd,
    canTextArrived,
    openText = false,
}: {
    jobId: number;
    parts: PartItem[];
    suppliers: SupplierOption[];
    canAdd: boolean;
    canTextArrived: boolean;
    /** Opened from the "Parts ready" notification: the text is ready to check. */
    openText?: boolean;
}) {
    const t = useTrans();
    const [adding, setAdding] = useState(false);
    const [arrivedOpen, setArrivedOpen] = useState(openText && canTextArrived);

    return (
        <section className="da-card overflow-hidden" data-test="part-card">
            <div className="flex items-center justify-between gap-2 px-4 pt-3">
                <h2 className="font-semibold">{t('parts.on_job')}</h2>
                {canAdd && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setAdding(true)}
                        data-test="part-needed"
                    >
                        <Plus /> {t('parts.need_part')}
                    </Button>
                )}
            </div>
            {parts.length === 0 ? (
                <p className="px-4 pt-1 pb-3 text-sm text-muted-foreground">
                    {t('parts.none_on_job')}
                </p>
            ) : (
                <ul className="divide-y">
                    {parts.map((part) => (
                        <PartRow
                            key={part.id}
                            part={part}
                            suppliers={suppliers}
                            showJob={false}
                        />
                    ))}
                </ul>
            )}
            {canTextArrived && (
                <div className="p-3 pt-0">
                    <Button
                        variant="outline"
                        size="lg"
                        className="w-full"
                        onClick={() => setArrivedOpen(true)}
                        data-test="part-arrived-text"
                    >
                        <PackageCheck /> {t('parts.ready.text_customer')}
                    </Button>
                    <PartArrivedSheet
                        open={arrivedOpen}
                        onOpenChange={setArrivedOpen}
                        jobId={jobId}
                    />
                </div>
            )}
            <AddPartSheet
                open={adding}
                onOpenChange={setAdding}
                jobId={jobId}
                suppliers={suppliers}
            />
        </section>
    );
}

export function PartArrivedSheet({
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
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setError(null);
        setText('');
        fetch(partArrived(jobId).url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((body: { text?: string }) => setText(body.text ?? ''))
            .catch(() => setError(t('automation.inbox.failed')));
    }, [open, jobId, t]);

    const send = () =>
        router.post(
            sendArrived(jobId).url,
            { text },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    setError(String(Object.values(errors)[0] ?? '')),
            },
        );

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[90dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="part-arrived-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>
                        {t('automation.parts.arrived_title')}
                    </SheetTitle>
                    <SheetDescription>
                        {t('automation.parts.arrived_hint')}
                    </SheetDescription>
                </SheetHeader>
                <Textarea
                    value={text}
                    rows={7}
                    maxLength={1600}
                    onChange={(e) => setText(e.target.value)}
                    aria-label={t('automation.parts.arrived_title')}
                />
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <Button
                    size="lg"
                    className="w-full"
                    loading={busy}
                    disabled={text.trim() === ''}
                    onClick={send}
                    data-test="part-arrived-send"
                >
                    <Send /> {t('automation.parts.send')}
                </Button>
            </SheetContent>
        </Sheet>
    );
}
