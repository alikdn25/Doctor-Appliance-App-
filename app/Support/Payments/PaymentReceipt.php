<?php

namespace App\Support\Payments;

use App\Enums\MessageKind;
use App\Enums\PaymentMethod;
use App\Enums\SmsMode;
use App\Mail\ReceiptMail;
use App\Messaging\Messenger;
use App\Messaging\ReviewRequests;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\PublicDocument;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Send receipt" on the Paid screen (owner's task of November 21): by SMS and / or email, only when Send is pressed.
 * The receipt: company (brand) name, date, invoice number, lines, taxes by name, total, the way it was paid, "Paid".
 * "Request review" ticked puts the review request (the same text as the review request, and in the email the same
 * picture as invoice emails) into the receipt message: one message, not two. Must run in a tenant context.
 */
class PaymentReceipt
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly ReviewRequests $reviews,
        private readonly PaidCustomer $customers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  channels, phone, email, names, addresses, review, google_profile_id
     * @return array{sent: list<string>, sms_url: string|null}
     */
    public function send(Payment $payment, array $data, User $user): array
    {
        $invoice = $payment->invoice()->firstOrFail();
        $channels = array_values(array_intersect(['sms', 'email'], (array) ($data['channels'] ?? [])));

        if ($channels === []) {
            throw ValidationException::withMessages(['channels' => __('charges.receipt.errors.channel')]);
        }

        $phone = PaidCustomer::phone($data);
        $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null;

        if (in_array('sms', $channels, true) && $phone === null) {
            throw ValidationException::withMessages(['phone' => __('reviews.prompt.invalid_phone')]);
        }
        if (in_array('email', $channels, true) && $email === null) {
            throw ValidationException::withMessages(['email' => __('charges.receipt.errors.email')]);
        }
        if (in_array('sms', $channels, true) && currentCompany()->sms_mode === SmsMode::Off) {
            throw ValidationException::withMessages(['channels' => __('reviews.prompt.sms_off')]);
        }

        $profile = ($data['review'] ?? false) === true
            ? (GoogleProfile::query()->find($data['google_profile_id'] ?? null) ?? throw ValidationException::withMessages(['google_profile_id' => __('reviews.prompt.choose_location')]))
            : null;

        // The details typed here are kept: a Walk-in invoice moves to the real customer.
        $this->customers->applyToInvoice($invoice, $data);
        $invoice->refresh()->load(['customer', 'job']);
        PublicDocument::token($invoice);

        $sent = [];
        $smsUrl = null;
        $message = null;

        if (in_array('sms', $channels, true)) {
            $body = $this->smsText($invoice, $payment, $profile);

            if (currentCompany()->sms_mode === SmsMode::Automatic) {
                $message = $this->messenger->smsToCustomerNumber(MessageKind::Receipt, $invoice->customer, $invoice->job, $phone, $body, $user);
                if (in_array($message->status, ['blocked', 'failed'], true)) {
                    throw ValidationException::withMessages(['phone' => (string) $message->status_reason]);
                }
            } else {
                // From technician's phone: the phone's messages app opens with the receipt.
                $message = $this->messenger->openedOnPhone(MessageKind::Receipt, $invoice->customer, $invoice->job, $phone, $body, $user);
                $smsUrl = 'sms:'.$phone.'?body='.rawurlencode($body);
            }
            $sent[] = PhoneNumber::display($phone);
        }

        if (in_array('email', $channels, true)) {
            Mail::to($email)->queue(new ReceiptMail($invoice, $profile?->id));
            $sent[] = $email;
        }

        if ($profile !== null) {
            ReviewRequest::query()->updateOrCreate(['service_job_id' => $invoice->service_job_id], [
                'customer_id' => $invoice->customer_id,
                'google_profile_id' => $profile->id,
                'status' => ReviewRequest::SENT,
                'channel' => $message?->channel ?? Message::EMAIL,
                'skip_reason' => null,
                'send_after' => null,
                'sent_at' => now(),
                'message_id' => $message?->id,
            ]);
            // The "Send a review request?" offer two hours later is not needed any more.
            $invoice->forceFill(['review_offered_at' => $invoice->review_offered_at ?? now()])->saveQuietly();
        }

        $this->audit->record('invoice.receipt_sent', $invoice, ['number' => $invoice->number, 'to' => implode(', ', $sent), 'review' => $profile !== null]);

        return ['sent' => $sent, 'sms_url' => $smsUrl];
    }

    /**
     * The receipt as a text message.
     */
    public function smsText(Invoice $invoice, Payment $payment, ?GoogleProfile $profile): string
    {
        $print = DocumentPrint::data($invoice);
        $brand = $print['brand']['name'] ?? $print['brand']['fallback_name'];
        $lines = [
            __('charges.receipt.sms_text.title', ['brand' => $brand]),
            __('charges.receipt.sms_text.invoice', ['number' => $invoice->number, 'date' => DocumentPrint::date($payment->received_at)]),
            '',
        ];

        foreach ($print['items'] as $item) {
            $lines[] = $item['description'].': '.$item['total'];
        }
        foreach ($print['taxes'] as $tax) {
            $lines[] = $tax['label'].': '.$tax['amount'];
        }
        $lines[] = __('charges.receipt.sms_text.total', ['total' => $print['total']]);
        $lines[] = __('charges.receipt.sms_text.paid', ['method' => self::methodLabel($payment)]);
        $lines[] = PublicDocument::url($invoice);

        $text = implode("\n", $lines);

        return $profile === null ? $text : $text."\n\n".$this->reviews->text($invoice->job, $profile);
    }

    public static function methodLabel(Payment $payment): string
    {
        return $payment->method === PaymentMethod::Online && $payment->provider !== null
            ? __('payments.online', ['provider' => ucfirst($payment->provider)])
            : $payment->method->label();
    }
}
