<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Google review requests (SPEC §8). The only way to send one: after an invoice is sent, the user answers
 * "Send Google Review request?" and picks a location (Google profile) and a phone number; the link goes out as a
 * separate SMS (Automatic mode) or opens on the technician's phone (sms: link). Nothing is sent automatically.
 * Every customer gets the same request: no incentive, no "are you happy?" filter. Must run in a tenant context.
 */
class ReviewRequests
{
    public function __construct(private readonly Messenger $messenger) {}

    /**
     * The prompt shown after the invoice is sent: locations with their ready texts, and the customer's phone.
     *
     * @return array<string, mixed>
     */
    public function prompt(Invoice $invoice): array
    {
        $company = currentCompany();
        $invoice->loadMissing(['customer', 'job.brand']);
        $job = $invoice->job;
        $profiles = GoogleProfile::query()->orderBy('label')->get();
        $default = $job->brand?->google_profile_id;

        return [
            'url' => route('invoices.review-request', $invoice),
            'mode' => $company->sms_mode->value,
            'blocked' => $company->sms_mode === SmsMode::Off ? __('reviews.prompt.sms_off') : null,
            'phone' => $this->messenger->mobile($invoice->customer)?->number,
            'profiles' => $profiles->map(fn (GoogleProfile $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'text' => $this->text($job, $p),
            ])->values()->all(),
            'default_profile_id' => match (true) {
                $profiles->contains('id', $default) => $default,
                $profiles->count() === 1 => $profiles->first()->id,
                default => null,
            },
        ];
    }

    /**
     * "Send" in the prompt: an SMS from the company number (Automatic) or the record that the text was opened on
     * the technician's phone. The job keeps the latest request.
     */
    public function send(Invoice $invoice, GoogleProfile $profile, string $to, User $user): Message
    {
        $invoice->loadMissing(['customer', 'job']);
        $job = $invoice->job;
        $body = $this->text($job, $profile);

        $message = currentCompany()->sms_mode === SmsMode::Automatic
            ? $this->messenger->smsToCustomerNumber(MessageKind::ReviewRequest, $invoice->customer, $job, $to, $body, $user)
            : $this->messenger->openedOnPhone(MessageKind::ReviewRequest, $invoice->customer, $job, $to, $body, $user);

        $blocked = in_array($message->status, ['blocked', 'failed'], true);

        ReviewRequest::query()->updateOrCreate(['service_job_id' => $job->id], [
            'customer_id' => $invoice->customer_id,
            'google_profile_id' => $profile->id,
            'status' => $blocked ? ReviewRequest::SKIPPED : ReviewRequest::SENT,
            'channel' => $message->channel,
            'skip_reason' => $blocked ? $message->status_reason : null,
            'send_after' => null,
            'sent_at' => $blocked ? null : now(),
            'message_id' => $message->id,
        ]);

        return $message;
    }

    public function text(ServiceJob $job, ?GoogleProfile $profile): string
    {
        return MessageTemplates::render(currentCompany(), MessageKind::ReviewRequest, [
            ...MessageContext::for($job->customer, $job),
            'review_link' => $profile?->review_url,
        ]);
    }
}
