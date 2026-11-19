import { router } from '@inertiajs/react';
import { Camera, Receipt } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PartArrivedSheet } from '@/components/jobs/part-card';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTrans } from '@/lib/i18n';
import { handReceipt } from '@/lib/receipt-scan';
import { create as createExpense } from '@/routes/expenses';

/**
 * After a part is bought or a Parts / Materials task is done ("offerReceipt" flash): "Add a receipt?" — the camera,
 * then the new expense filled in from the photo (nothing saved until Save). After the last part of a job is on hand
 * ("partsReadyJob" flash): the text with the three nearest windows, sent only after Send.
 */
export function AfterPartPrompts() {
    const t = useTrans();
    const [receipt, setReceipt] = useState(false);
    const [readyJob, setReadyJob] = useState<number | null>(null);
    const input = useRef<HTMLInputElement>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash ?? {};
                if (flash.offerReceipt) setReceipt(true);
                if (typeof flash.partsReadyJob === 'number') {
                    setReadyJob(flash.partsReadyJob);
                }
            }),
        [],
    );

    return (
        <>
            <Sheet
                open={receipt && readyJob === null}
                onOpenChange={setReceipt}
            >
                <SheetContent
                    side="bottom"
                    className="mx-auto flex max-w-xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                    data-test="receipt-offer"
                >
                    <SheetHeader className="p-0 pr-8">
                        <SheetTitle className="flex items-center gap-2">
                            <Receipt className="size-5" />
                            {t('parts.receipt.title')}
                        </SheetTitle>
                        <SheetDescription>
                            {t('parts.receipt.hint')}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            size="lg"
                            onClick={() => input.current?.click()}
                            data-test="receipt-offer-take"
                        >
                            <Camera /> {t('parts.receipt.take')}
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            onClick={() => setReceipt(false)}
                        >
                            {t('parts.receipt.skip')}
                        </Button>
                    </div>
                    <input
                        ref={input}
                        type="file"
                        accept="image/*"
                        capture="environment"
                        className="hidden"
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            event.target.value = '';
                            if (!file) return;
                            setReceipt(false);
                            handReceipt(file);
                            router.visit(
                                createExpense({ query: { scan: 1 } }).url,
                            );
                        }}
                    />
                </SheetContent>
            </Sheet>
            {readyJob !== null && (
                <PartArrivedSheet
                    open
                    onOpenChange={(open) => !open && setReadyJob(null)}
                    jobId={readyJob}
                />
            )}
        </>
    );
}
