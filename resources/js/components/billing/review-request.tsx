import { router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';
import { openOnPhone } from '@/components/messaging/job-messaging';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { reviewRequest } from '@/routes/jobs';

export type ReviewRequestData = {
    job_id: number;
    mode: 'automatic' | 'technician_phone' | 'off';
    status: string | null;
    sent: boolean;
    phone: string | null;
    can_email: boolean;
    text: string;
    locations: { id: number; label: string; url: string }[];
};

/**
 * The last step of a job: once the invoice is paid, the technician chooses the location (Google profile)
 * and sends its review link to the customer. Nothing is preselected or remembered: the location is chosen every time.
 */
export function ReviewRequestSection({
    review,
}: {
    review: ReviewRequestData;
}) {
    const t = useTrans();
    const [chosen, setChosen] = useState<number | null>(null);
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const onPhone = review.mode === 'technician_phone';
    const reachable = onPhone
        ? review.phone !== null
        : review.phone !== null || review.can_email;
    const location = review.locations.find((l) => l.id === chosen);

    const send = () => {
        if (!location) return;
        if (onPhone) {
            openOnPhone(
                review.job_id,
                'review_request',
                review.phone!,
                review.text.replaceAll('{review_link}', location.url),
            );
            setChosen(null);

            return;
        }
        router.post(
            reviewRequest(review.job_id).url,
            { location_id: location.id },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setChosen(null),
                onError: (errors) => setError(errors.location_id),
            },
        );
    };

    return (
        <section className="space-y-3 rounded-lg border p-3 text-sm">
            <h2 className="flex items-center gap-2 text-base font-medium">
                <Star className="size-4" /> {t('reviews.finish_title')}
            </h2>
            {review.status && (
                <p className="text-xs text-muted-foreground">{review.status}</p>
            )}
            {review.locations.length === 0 ? (
                <p className="text-muted-foreground">
                    {t('reviews.no_locations')}
                </p>
            ) : (
                <>
                    <p className="text-xs font-medium">
                        {t('reviews.choose_location')}
                    </p>
                    <div
                        role="radiogroup"
                        aria-label={t('reviews.choose_location')}
                        className="flex flex-wrap gap-2"
                    >
                        {review.locations.map((l) => (
                            <Button
                                key={l.id}
                                type="button"
                                role="radio"
                                aria-checked={chosen === l.id}
                                variant={
                                    chosen === l.id ? 'default' : 'outline'
                                }
                                className="min-h-11"
                                onClick={() => {
                                    setChosen(l.id);
                                    setError(undefined);
                                }}
                            >
                                {l.label}
                            </Button>
                        ))}
                    </div>
                    {error && (
                        <p className="text-xs text-destructive">{error}</p>
                    )}
                    <Button
                        className="h-11 w-full"
                        disabled={!location || !reachable || processing}
                        onClick={send}
                    >
                        <Star />{' '}
                        {review.sent
                            ? t('reviews.send_again')
                            : t('reviews.send_request')}
                    </Button>
                </>
            )}
            {!reachable && (
                <p className="text-xs text-muted-foreground">
                    {t('reviews.no_contact')}
                </p>
            )}
        </section>
    );
}
