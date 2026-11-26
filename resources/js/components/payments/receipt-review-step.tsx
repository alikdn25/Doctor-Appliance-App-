import { CheckCircle2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** The customer's email and the review locations (the one picked last time first). */
export type ReviewStepSetup = {
    customer_email: string | null;
    profiles: { id: number; label: string }[];
    default_profile_id: number | null;
};

export type ReviewStepValues = {
    send_receipt: boolean;
    request_review: boolean;
    google_profile_id: number | null;
    email: string | null;
};

function Toggle({
    checked,
    onChange,
    label,
    testId,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    testId: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            data-test={testId}
            className="da-press flex min-h-14 w-full items-center justify-between gap-3 rounded-2xl px-1 text-left text-base font-semibold"
        >
            {label}
            <span
                aria-hidden="true"
                className={cn(
                    'relative h-8 w-14 shrink-0 rounded-full transition-colors',
                    checked ? 'bg-[#1e8cfd]' : 'da-track',
                )}
            >
                <span
                    className={cn(
                        'da-raised absolute top-1 size-6 rounded-full bg-white transition-all',
                        checked ? 'left-7' : 'left-1',
                    )}
                />
            </span>
        </button>
    );
}

/**
 * "Receipt & review" (owner's task of November 26), after Paid — Cash / E-transfer, a card payment or No charge:
 * Send receipt (off by default; not shown for No charge) and Request review (on by default) with the location, the
 * customer's email right here when there is none (saved to the customer), and one Done. Without an email nothing is
 * sent and the job is still closed.
 */
export function ReceiptReviewStep({
    setup,
    showReceipt,
    summary,
    busy,
    onDone,
}: {
    setup: ReviewStepSetup;
    showReceipt: boolean;
    summary: ReactNode;
    busy: boolean;
    onDone: (values: ReviewStepValues) => void;
}) {
    const t = useTrans();
    const [receipt, setReceipt] = useState(false);
    const [review, setReview] = useState(true);
    const [profile, setProfile] = useState(
        setup.default_profile_id ? String(setup.default_profile_id) : '',
    );
    const [email, setEmail] = useState('');
    const needsEmail = (receipt || review) && !setup.customer_email;
    const typed = email.trim();

    return (
        <div className="grid gap-3" data-test="receipt-review-step">
            <div className="da-card flex items-center gap-3 rounded-[18px] p-4">
                <span className="da-success flex size-11 shrink-0 items-center justify-center rounded-full">
                    <CheckCircle2 className="size-6" />
                </span>
                <div className="min-w-0 text-base font-semibold">{summary}</div>
            </div>

            <div className="da-card grid gap-1 rounded-[18px] px-3 py-2">
                {showReceipt && (
                    <Toggle
                        checked={receipt}
                        onChange={setReceipt}
                        label={t('jobs.close_out.send_receipt')}
                        testId="close-send-receipt"
                    />
                )}
                <Toggle
                    checked={review}
                    onChange={setReview}
                    label={t('jobs.close_out.request_review')}
                    testId="close-request-review"
                />
                {review &&
                    (setup.profiles.length === 0 ? (
                        <p className="pb-2 text-sm text-muted-foreground">
                            {t('jobs.close_out.no_locations')}
                        </p>
                    ) : (
                        <label className="grid gap-1 pb-2 text-sm">
                            <span className="text-muted-foreground">
                                {t('jobs.close_out.location')}
                            </span>
                            <NativeSelect
                                value={profile}
                                onChange={(e) => setProfile(e.target.value)}
                                data-test="close-review-location"
                            >
                                {setup.profiles.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                    ))}
            </div>

            {needsEmail && (
                <label className="grid gap-1 text-sm">
                    <span className="font-medium">
                        {t('jobs.close_out.email')}
                    </span>
                    <Input
                        type="email"
                        inputMode="email"
                        autoComplete="email"
                        value={email}
                        placeholder="name@example.com"
                        onChange={(e) => setEmail(e.target.value)}
                        data-test="close-email"
                    />
                    <span className="text-xs text-muted-foreground">
                        {t('jobs.close_out.email_hint')}
                    </span>
                </label>
            )}

            <Button
                size="lg"
                className="min-h-14 text-lg"
                loading={busy}
                disabled={busy}
                onClick={() =>
                    onDone({
                        send_receipt: showReceipt && receipt,
                        request_review: review && profile !== '',
                        google_profile_id:
                            review && profile !== '' ? Number(profile) : null,
                        email:
                            needsEmail && /^\S+@\S+\.\S+$/.test(typed)
                                ? typed
                                : null,
                    })
                }
                data-test="close-done"
            >
                {t('jobs.close_out.done')}
            </Button>
        </div>
    );
}
