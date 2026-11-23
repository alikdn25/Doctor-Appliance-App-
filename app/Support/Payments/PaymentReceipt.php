<?php

namespace App\Support\Payments;

use App\Enums\PaymentMethod;
use App\Mail\ReceiptMail;
use App\Messaging\ReviewRequests;
use App\Models\GoogleProfile;
use App\Models\Message;
use App\Models\Payment;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Billing\PublicDocument;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Send receipt" on the Paid screen (owner's task of November 21; email only since November 22): the receipt goes
 * by email, only when Send is pressed. The email comes from the customer, or is typed there and kept on the customer.
 * The receipt: company (brand) name, date, invoice number, lines, taxes by name, total, the way it was paid, "Paid".
 * "Request review" ticked puts the review block (text and the company's picture, as in invoice emails) into the same
 * email. Must run in a tenant context.
 */
class PaymentReceipt
{
    public function __construct(
        private readonly PaidCustomer $customers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  email, names, addresses, review, google_profile_id, review_text
     * @return array{sent: list<string>}
     */
    public function send(Payment $payment, array $data, User $user): array
    {
        $invoice = $payment->invoice()->firstOrFail();
        $email = filter_var(mb_strtolower(trim((string) ($data['email'] ?? ''))), FILTER_VALIDATE_EMAIL) ?: null;

        if ($email === null) {
            throw ValidationException::withMessages(['email' => __('charges.receipt.errors.email')]);
        }

        $profile = ($data['review'] ?? false) === true
            ? (GoogleProfile::query()->find($data['google_profile_id'] ?? null) ?? throw ValidationException::withMessages(['google_profile_id' => __('reviews.prompt.choose_location')]))
            : null;

        // The details typed here are kept: a Walk-in invoice moves to the real customer; the email goes on the customer.
        $this->customers->applyToInvoice($invoice, [...$data, 'email' => $email]);
        $invoice->refresh()->load(['customer', 'job.brand', 'brand']);
        ReviewRequests::keepEmail($invoice->customer, $email);
        PublicDocument::token($invoice);

        // The review text as shown (and maybe changed) on the Paid screen; the ready text when none came.
        $reviewText = $profile === null ? null
            : (ReviewRequests::personal($data['review_text'] ?? null, $profile) ?? ReviewRequests::text($invoice->customer, $invoice->job?->brand?->name ?? $invoice->brand?->name, $profile));

        Mail::to($email)->queue(new ReceiptMail($invoice, $profile?->id, $reviewText));

        if ($profile !== null) {
            ReviewRequest::query()->updateOrCreate(['service_job_id' => $invoice->service_job_id], [
                'customer_id' => $invoice->customer_id,
                'google_profile_id' => $profile->id,
                'status' => ReviewRequest::SENT,
                'channel' => Message::EMAIL,
                'skip_reason' => null,
                'send_after' => null,
                'sent_at' => now(),
                'message_id' => null,
            ]);
            // The "Send a review request?" offer two hours later is not needed any more.
            $invoice->forceFill(['review_offered_at' => $invoice->review_offered_at ?? now()])->saveQuietly();
        }

        $this->audit->record('invoice.receipt_sent', $invoice, ['number' => $invoice->number, 'to' => $email, 'review' => $profile !== null]);

        return ['sent' => [$email]];
    }

    public static function methodLabel(Payment $payment): string
    {
        return $payment->method === PaymentMethod::Online && $payment->provider !== null
            ? __('payments.online', ['provider' => ucfirst($payment->provider)])
            : $payment->method->label();
    }
}
