import { Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ReviewPromptDialog } from '@/components/billing/review-prompt';
import type { ReviewPrompt } from '@/components/messaging/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTrans } from '@/lib/i18n';

type PaidInFull = {
    invoice_id: number;
    number: string;
    job_id: number;
    complete: { close_url: string } | { finish_url: string } | null;
    review: ReviewPrompt;
};

/**
 * After a payment settles an invoice: "Send Google Review request?" first, then "Complete job" in one tap (or
 * "Finish visit" while a visit is under way). Lives in the layout so it survives the page reloads in between.
 */
export function PaidInFullFlow() {
    const t = useTrans();
    const [paid, setPaid] = useState<PaidInFull | null>(null);
    const [step, setStep] = useState<'review' | 'complete' | null>(null);
    const [closing, setClosing] = useState(false);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const data = (event as CustomEvent).detail?.flash
                    ?.paid_in_full as PaidInFull | undefined;

                if (!data) {
                    return;
                }

                setPaid(data);
                setStep(
                    data.review ? 'review' : data.complete ? 'complete' : null,
                );
            }),
        [],
    );

    const afterReview = () => setStep(paid?.complete ? 'complete' : null);

    const complete = () => {
        if (!paid?.complete || !('close_url' in paid.complete)) {
            return;
        }

        setClosing(true);
        router.post(
            paid.complete.close_url,
            { outcome: 'repaired' },
            {
                onFinish: () => {
                    setClosing(false);
                    setStep(null);
                },
            },
        );
    };

    if (!paid) {
        return null;
    }

    return (
        <>
            {paid.review && (
                <ReviewPromptDialog
                    prompt={paid.review}
                    open={step === 'review'}
                    onOpenChange={(open) => {
                        if (!open) {
                            afterReview();
                        }
                    }}
                />
            )}

            <Dialog
                open={step === 'complete'}
                onOpenChange={(open) => !open && setStep(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader className="items-center text-center">
                        <span className="da-paid flex size-16 items-center justify-center rounded-full text-white">
                            <Check className="size-8" strokeWidth={3} />
                        </span>
                        <DialogTitle>
                            {t('payments.paid_in_full.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('payments.paid_in_full.description', {
                                number: paid.number,
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    {paid.complete && 'finish_url' in paid.complete ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                {t('payments.paid_in_full.finish_hint')}
                            </p>
                            <Button className="h-12 text-base" asChild>
                                <Link
                                    href={paid.complete.finish_url}
                                    onClick={() => setStep(null)}
                                >
                                    {t('payments.paid_in_full.finish')}
                                </Link>
                            </Button>
                        </>
                    ) : (
                        <Button
                            className="h-12 text-base"
                            disabled={closing}
                            onClick={complete}
                        >
                            <Check /> {t('payments.paid_in_full.complete')}
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        className="h-11"
                        onClick={() => setStep(null)}
                    >
                        {t('payments.paid_in_full.not_now')}
                    </Button>
                </DialogContent>
            </Dialog>
        </>
    );
}
