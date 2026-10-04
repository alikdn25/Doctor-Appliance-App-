import { router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';
import { openOnPhone } from '@/components/messaging/job-messaging';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    links: { label: string; url: string }[];
};

const LAST_LINK_KEY = 'review-link';

function lastLink(): string {
    try {
        return window.localStorage.getItem(LAST_LINK_KEY) ?? '';
    } catch {
        return '';
    }
}

/**
 * The last step of a job: once the invoice is paid, the technician picks or pastes the Google review link
 * of whichever profile fits and sends it to the customer. No profile is bound to the brand or the job.
 */
export function ReviewRequestSection({
    review,
}: {
    review: ReviewRequestData;
}) {
    const t = useTrans();
    const [link, setLink] = useState(
        () => lastLink() || (review.links[0]?.url ?? ''),
    );
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const onPhone = review.mode === 'technician_phone';
    const reachable = onPhone
        ? review.phone !== null
        : review.phone !== null || review.can_email;
    const valid = /^https:\/\/\S+$/.test(link.trim());

    const send = () => {
        const url = link.trim();
        try {
            window.localStorage.setItem(LAST_LINK_KEY, url);
        } catch {
            // Remembering the link is only a convenience.
        }
        if (onPhone) {
            openOnPhone(
                review.job_id,
                'review_request',
                review.phone!,
                review.text.replaceAll('{review_link}', url),
            );

            return;
        }
        router.post(
            reviewRequest(review.job_id).url,
            { link: url },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(errors.link),
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
            {review.links.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {review.links.map((saved) => (
                        <Button
                            key={saved.url}
                            type="button"
                            size="sm"
                            variant={link === saved.url ? 'default' : 'outline'}
                            className="min-h-10"
                            onClick={() => setLink(saved.url)}
                        >
                            {saved.label}
                        </Button>
                    ))}
                </div>
            )}
            <div className="space-y-1">
                <label htmlFor="review-link" className="text-xs font-medium">
                    {t('reviews.link_label')}
                </label>
                <Input
                    id="review-link"
                    type="url"
                    inputMode="url"
                    placeholder="https://g.page/r/…/review"
                    value={link}
                    onChange={(e) => {
                        setLink(e.target.value);
                        setError(undefined);
                    }}
                />
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
            <Button
                className="h-11 w-full"
                disabled={!valid || !reachable || processing}
                onClick={send}
            >
                <Star />{' '}
                {review.sent
                    ? t('reviews.send_again')
                    : t('reviews.send_request')}
            </Button>
            {!reachable && (
                <p className="text-xs text-muted-foreground">
                    {t('reviews.no_contact')}
                </p>
            )}
        </section>
    );
}
