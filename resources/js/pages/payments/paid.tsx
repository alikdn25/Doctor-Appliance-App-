import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Send } from 'lucide-react';
import { useState } from 'react';
import {
    CustomerFields,
    emptyCustomer,
} from '@/components/payments/customer-fields';
import type { CustomerDetails } from '@/components/payments/customer-fields';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';

type Props = {
    payment: {
        id: number;
        amount: string;
        method: string;
        reference: string | null;
        tip: string | null;
    };
    invoice: {
        id: number;
        number: string;
        status: string;
        paid: boolean;
        balance: string;
        url: string;
        job_url: string | null;
    };
    customer: {
        walk_in: boolean;
        name: string;
        first_name: string;
        last_name: string;
        phone: string;
        email: string;
        has_address: boolean;
    };
    profiles: { id: number; label: string; text: string }[];
    default_profile_id: number | null;
    send_url: string;
};

/**
 * Paid (owner's task of November 21): amount, way of payment and invoice number, then Send receipt — by email only
 * (the customer's email, or typed here and kept on the customer; Send waits for one), the other details right here when they are missing (saved to the customer and the invoice), and
 * "Request review" with a location (ticked by hand, for any customer; the request goes inside the receipt message).
 * Nothing is sent before Send. Also opened by the "Invoice #N paid" notification of an online payment.
 */
export default function Paid(props: Props) {
    const t = useTrans();
    const country = usePage().props.auth.company?.country;
    const { payment, invoice, customer } = props;
    const [email, setEmail] = useState(customer.email);
    const [details, setDetails] = useState<CustomerDetails>(() => ({
        ...emptyCustomer(),
        phone: customer.phone,
        first_name: customer.first_name,
        last_name: customer.last_name,
        email: customer.email,
    }));
    const missing = customer.walk_in || !customer.has_address;
    const validEmail = /^\S+@\S+\.\S+$/.test(email.trim());
    const [showDetails, setShowDetails] = useState(customer.walk_in);
    const [review, setReview] = useState(false);
    const [profile, setProfile] = useState<string>(
        props.default_profile_id ? String(props.default_profile_id) : '',
    );
    // The ready review text of the chosen location; once changed by hand it is sent as written.
    const [reviewText, setReviewText] = useState<string | null>(null);
    const readyText =
        props.profiles.find((p) => String(p.id) === profile)?.text ?? '';
    const [sending, setSending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [sent, setSent] = useState(false);

    const send = () => {
        setSending(true);
        router.post(
            props.send_url,
            {
                ...details,
                email,
                review,
                google_profile_id: review && profile ? Number(profile) : null,
                review_text: review ? reviewText : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => setSent(true),
                onError: (e) => {
                    const found = e as Record<string, string>;
                    setErrors(found);
                    if (found.phone) setShowDetails(true);
                },
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <>
            <Head title={t('charges.paid.title')} />
            <ScreenHeader title={t('charges.paid.title')} back={invoice.url} />
            <div
                className="mx-auto grid w-full max-w-md gap-4 p-4 pb-24 sm:p-6"
                data-test="paid"
            >
                <div className="da-card grid justify-items-center gap-2 rounded-[18px] p-6 text-center">
                    <span className="da-success flex size-16 items-center justify-center rounded-full">
                        <CheckCircle2 className="size-9" />
                    </span>
                    <p className="text-4xl font-bold" data-test="paid-amount">
                        {payment.amount}
                    </p>
                    <p className="text-base font-semibold text-green-700 dark:text-green-400">
                        {t('charges.paid.title')} · {payment.method}
                    </p>
                    {payment.reference && (
                        <p className="text-sm text-muted-foreground">
                            {payment.reference}
                        </p>
                    )}
                    {payment.tip && (
                        <p className="text-sm text-muted-foreground">
                            {t('charges.paid.tip', { amount: payment.tip })}
                        </p>
                    )}
                    <p className="text-sm text-muted-foreground">
                        {t('charges.paid.invoice', { number: invoice.number })}
                        {' · '}
                        {customer.name}
                    </p>
                    {!invoice.paid && (
                        <p className="text-sm font-medium text-amber-700">
                            {t('charges.paid.balance_left', {
                                amount: invoice.balance,
                            })}
                        </p>
                    )}
                </div>

                <section
                    className="da-card grid gap-3 rounded-[18px] p-4"
                    data-test="send-receipt"
                >
                    <h2 className="text-base font-semibold">
                        {t('charges.receipt.title')}
                    </h2>
                    <label className="grid gap-1 text-sm">
                        <span className="text-muted-foreground">
                            {t('charges.receipt.email')}
                        </span>
                        <Input
                            type="email"
                            inputMode="email"
                            autoComplete="email"
                            value={email}
                            placeholder={t('charges.receipt.email_placeholder')}
                            onChange={(e) => setEmail(e.target.value)}
                            aria-invalid={!!errors.email}
                            data-test="receipt-email"
                        />
                        {errors.email ? (
                            <span className="text-xs text-danger">
                                {errors.email}
                            </span>
                        ) : (
                            !customer.email && (
                                <span className="text-xs text-muted-foreground">
                                    {t('charges.receipt.email_hint')}
                                </span>
                            )
                        )}
                    </label>

                    {(missing || showDetails) && (
                        <div className="grid gap-2">
                            <button
                                type="button"
                                className="text-left text-sm font-medium text-link"
                                onClick={() => setShowDetails(!showDetails)}
                                aria-expanded={showDetails}
                                data-test="receipt-details-toggle"
                            >
                                {t('charges.receipt.details')}
                            </button>
                            {showDetails && (
                                <>
                                    <p className="text-xs text-muted-foreground">
                                        {t('charges.receipt.details_hint')}
                                    </p>
                                    <CustomerFields
                                        value={details}
                                        onChange={setDetails}
                                        country={country}
                                        errors={errors}
                                        hideEmail
                                    />
                                </>
                            )}
                            {!showDetails && errors.phone && (
                                <p className="text-sm text-danger">
                                    {errors.phone}
                                </p>
                            )}
                        </div>
                    )}

                    <label className="flex min-h-10 items-start gap-2 text-sm">
                        <Checkbox
                            checked={review}
                            onCheckedChange={(c) => setReview(c === true)}
                            data-test="receipt-review"
                        />
                        <span>
                            {t('charges.receipt.review')}
                            <span className="block text-xs text-muted-foreground">
                                {t('charges.receipt.review_hint')}
                            </span>
                        </span>
                    </label>
                    {review &&
                        (props.profiles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('charges.receipt.no_locations')}
                            </p>
                        ) : (
                            <label className="grid gap-1 text-sm">
                                <span className="text-muted-foreground">
                                    {t('charges.receipt.location')}
                                </span>
                                <NativeSelect
                                    value={profile}
                                    onChange={(e) => setProfile(e.target.value)}
                                    data-test="receipt-location"
                                >
                                    <option value="">
                                        {t('reviews.prompt.choose_location')}
                                    </option>
                                    {props.profiles.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                {errors.google_profile_id && (
                                    <span className="text-xs text-danger">
                                        {errors.google_profile_id}
                                    </span>
                                )}
                            </label>
                        ))}
                    {review && profile !== '' && (
                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground">
                                {t('reviews.prompt.text')}
                            </span>
                            <Textarea
                                rows={5}
                                maxLength={1000}
                                value={reviewText ?? readyText}
                                onChange={(e) => setReviewText(e.target.value)}
                                data-test="receipt-review-text"
                            />
                            <span className="text-xs text-muted-foreground">
                                {t('reviews.prompt.text_hint')}
                            </span>
                            {errors.review_text && (
                                <span className="text-xs text-danger">
                                    {errors.review_text}
                                </span>
                            )}
                        </label>
                    )}

                    <Button
                        size="lg"
                        loading={sending}
                        disabled={!validEmail || (review && profile === '')}
                        onClick={send}
                        data-test="receipt-send"
                    >
                        <Send /> {t('charges.receipt.send')}
                    </Button>
                    {sent && (
                        <p
                            className="text-center text-sm text-green-700"
                            role="status"
                        >
                            ✓
                        </p>
                    )}
                </section>

                <div className="grid grid-cols-2 gap-3">
                    <Button variant="outline" asChild>
                        <Link href={invoice.url}>
                            {t('charges.paid.open_invoice')}
                        </Link>
                    </Button>
                    {invoice.job_url && (
                        <Button variant="outline" asChild>
                            <Link href={invoice.job_url}>
                                {t('charges.paid.open_job')}
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}
