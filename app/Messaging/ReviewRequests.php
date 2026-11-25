<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Support\Reviews\ReviewText;
use App\Support\Reviews\ReviewTracking;

/**
 * Google review requests (SPEC §8). The only way to send one: after an invoice is sent or paid, the user answers
 * "Send Google Review request?", picks a location (Google profile) and the customer's email; the request goes by
 * email only (owner's task of November 22), with the review block and the company's picture. Nothing is sent
 * automatically. Every customer gets the same request: no incentive, no "are you happy?" filter. Must run in a
 * tenant context.
 */
class ReviewRequests
{
    public function __construct(private readonly Messenger $messenger) {}

    /**
     * The prompt shown after the invoice is sent or paid: locations with their ready texts, and the customer's email.
     *
     * @return array<string, mixed>
     */
    public function prompt(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer.primaryEmail', 'job.brand']);
        $job = $invoice->job;
        $profiles = GoogleProfile::query()->orderBy('label')->get();
        $default = $job->brand?->google_profile_id;

        return [
            'url' => route('invoices.review-request', $invoice),
            'email' => $invoice->customer?->primaryEmail?->email ?? $invoice->customer?->emails()->value('email'),
            'profiles' => $profiles->map(fn (GoogleProfile $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'text' => self::text($invoice->customer, $job->brand?->name, $p),
                'review_url' => $p->review_url,
            ])->values()->all(),
            'default_profile_id' => match (true) {
                $profiles->contains('id', $default) => $default,
                $profiles->count() === 1 => $profiles->first()->id,
                default => null,
            },
        ];
    }

    /**
     * "Send" in the prompt: an email to the address given (kept on the customer when they had none). The job keeps
     * the latest request.
     */
    public function send(Invoice $invoice, GoogleProfile $profile, string $email, User $user, ?string $text = null): Message
    {
        $invoice->loadMissing(['customer', 'job.brand']);
        $job = $invoice->job;
        $body = self::personal($text, $profile) ?? self::text($invoice->customer, $job->brand?->name, $profile);
        self::keepEmail($invoice->customer, $email);

        // The links go through our address, so a click is seen on the job (no reminder after it).
        $message = $this->messenger->emailToAddress(MessageKind::ReviewRequest, $invoice->customer, $job, $email,
            ReviewTracking::inText($body, $job, $profile), $user, reviewUrl: ReviewTracking::link($job, $profile));

        ReviewRequest::query()->updateOrCreate(['service_job_id' => $job->id], [
            'customer_id' => $invoice->customer_id,
            'google_profile_id' => $profile->id,
            'status' => ReviewRequest::SENT,
            'channel' => Message::EMAIL,
            'skip_reason' => null,
            'send_after' => null,
            'sent_at' => now(),
            'message_id' => $message->id,
        ]);

        return $message;
    }

    /**
     * An email typed for a customer who had none is kept on the customer.
     */
    public static function keepEmail(Customer $customer, string $email): void
    {
        $email = mb_strtolower(trim($email));

        if ($customer->is_walk_in || $customer->emails()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return;
        }

        $row = new CustomerEmail(['email' => $email, 'label' => 'personal']);
        $row->customer()->associate($customer);
        $row->is_primary = ! $customer->emails()->exists();
        $row->save();
    }

    /**
     * The text edited in the prompt for this one customer (the template stays as it is). The review link is what
     * the request is for, so it goes at the end when it was deleted from the text.
     */
    public static function personal(?string $text, GoogleProfile $profile): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if ($profile->review_url && ! str_contains($text, $profile->review_url)) {
            $text .= "\n".$profile->review_url;
        }

        return $text;
    }

    /**
     * The ready text (ReviewText): "Hey Josh, …" with the customer's first name, "Thanks for choosing <brand>! …"
     * without a usable one; signed by the owner; the location's review link.
     */
    public static function text(?Customer $customer, ?string $brand, ?GoogleProfile $profile): string
    {
        $company = currentCompany();

        return ReviewText::render(ReviewText::customerFirstName($customer), $brand ?? $company->name, ReviewText::signer($company), $profile?->review_url);
    }
}
