import { router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { openOnPhone } from '@/components/messaging/job-messaging';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTrans } from '@/lib/i18n';
import { askForReview } from '@/routes/jobs';

export type ReviewRequestData = {
    job_id: number;
    mode: 'automatic' | 'technician_phone' | 'off';
    ask: boolean;
    status: string | null;
    sent: boolean;
    has_profile: boolean;
    phone: string | null;
    text: string;
};

/**
 * The last step of a job: once the invoice is paid, ask the customer for a Google review.
 * From the technician's phone it opens the text; otherwise it goes out automatically after the delay.
 */
export function ReviewRequestSection({
    review,
}: {
    review: ReviewRequestData;
}) {
    const t = useTrans();
    const phoneButton =
        review.mode === 'technician_phone' &&
        review.has_profile &&
        review.phone !== null &&
        !review.sent;

    return (
        <section className="space-y-2 rounded-lg border p-3 text-sm">
            <h2 className="flex items-center gap-2 text-base font-medium">
                <Star className="size-4" /> {t('reviews.finish_title')}
            </h2>
            {!review.has_profile && (
                <p className="text-muted-foreground">
                    {t('reviews.skipped.no_profile')}
                </p>
            )}
            {phoneButton ? (
                <Button
                    className="h-11 w-full"
                    onClick={() =>
                        openOnPhone(
                            review.job_id,
                            'review_request',
                            review.phone!,
                            review.text,
                        )
                    }
                >
                    <Star /> {t('reviews.send_request')}
                </Button>
            ) : (
                review.mode !== 'technician_phone' &&
                review.has_profile &&
                !review.sent && (
                    <label className="flex min-h-11 items-center gap-2">
                        <Checkbox
                            checked={review.ask}
                            onCheckedChange={(c) =>
                                router.put(
                                    askForReview(review.job_id).url,
                                    { ask: c === true },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        {t('reviews.ask_for_review')}
                    </label>
                )
            )}
            {review.has_profile && (
                <p className="text-xs text-muted-foreground">
                    {review.status ??
                        (review.mode === 'technician_phone'
                            ? t('reviews.phone_hint')
                            : t('reviews.paid_hint'))}
                </p>
            )}
        </section>
    );
}
