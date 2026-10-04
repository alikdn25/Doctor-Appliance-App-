<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Models\GoogleProfile;
use App\Models\Message;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Google review requests (SPEC §8). At the end of the work the technician picks or pastes the review link of whichever
 * Google profile fits (a company may have several) and sends it: from their phone, by SMS (Automatic mode) or by
 * email. No profile is bound to a brand or job. Every customer gets the same request: no incentive, no
 * "are you happy?" filter. Requests scheduled by earlier versions are still delivered. Must run in a tenant context.
 */
class ReviewRequests
{
    public function __construct(private readonly Messenger $messenger) {}

    /**
     * Sends a request scheduled by an earlier version that is now due (minute command).
     */
    public function deliver(ReviewRequest $request): void
    {
        if ($request->status !== ReviewRequest::SCHEDULED) {
            return;
        }

        $skip = $this->skipReason($request);
        if ($skip !== null) {
            $request->update(['status' => ReviewRequest::SKIPPED, 'skip_reason' => $skip]);

            return;
        }

        $job = $request->job;
        $message = $this->messenger->send(MessageKind::ReviewRequest, $job->customer, $job, $this->text($job, $request->profile?->review_url ?? $this->profile($job)?->review_url));

        $request->update([
            'status' => $message->status === 'blocked' ? ReviewRequest::SKIPPED : ReviewRequest::SENT,
            'skip_reason' => $message->status === 'blocked' ? $message->status_reason : null,
            'channel' => $message->channel,
            'sent_at' => $message->status === 'blocked' ? null : now(),
            'message_id' => $message->id,
        ]);
    }

    /**
     * From technician's phone: the text with the chosen link was opened on the phone (sms: link); record it as sent.
     */
    public function sentFromPhone(ServiceJob $job, string $to, string $body, User $user): void
    {
        $this->record($job, $this->messenger->openedOnPhone(MessageKind::ReviewRequest, $job->customer, $job, $to, $body, $user));
    }

    /**
     * Automatic or Off mode: the app sends the request with the chosen link by SMS, or by email.
     */
    public function sendWithLink(ServiceJob $job, string $link, User $user): Message
    {
        $message = $this->messenger->send(MessageKind::ReviewRequest, $job->customer, $job, $this->text($job, $link), $user);
        if ($message->status !== 'blocked') {
            $this->record($job, $message);
        }

        return $message;
    }

    /**
     * The request text with the given review link; null keeps the {review_link} placeholder for the phone to fill.
     */
    public function text(ServiceJob $job, ?string $link): string
    {
        return MessageTemplates::render(currentCompany(), MessageKind::ReviewRequest, [
            ...MessageContext::for($job->customer, $job),
            'review_link' => $link ?? '{review_link}',
        ]);
    }

    private function record(ServiceJob $job, Message $message): void
    {
        ReviewRequest::query()->updateOrCreate(['service_job_id' => $job->id], [
            'customer_id' => $job->customer_id,
            'google_profile_id' => null,
            'status' => ReviewRequest::SENT,
            'channel' => $message->channel,
            'skip_reason' => null,
            'sent_at' => now(),
            'message_id' => $message->id,
        ]);
    }

    /**
     * The brand's default profile, else the company's only/first profile.
     */
    public function profile(ServiceJob $job): ?GoogleProfile
    {
        $job->loadMissing('brand');
        $default = $job->brand?->google_profile_id ? GoogleProfile::query()->find($job->brand->google_profile_id) : null;

        return $default
            ?? GoogleProfile::query()->where('brand_id', $job->brand_id)->orderBy('id')->first()
            ?? GoogleProfile::query()->orderBy('id')->first();
    }

    /**
     * Why a request is not sent (null = send): no profile, a recent request to the same customer,
     * the job no longer paid, or "Ask for a review" turned off.
     */
    public function skipReason(ReviewRequest $request): ?string
    {
        $job = ServiceJob::query()->find($request->service_job_id);
        $company = currentCompany();

        if ($job === null || ! $job->ask_for_review) {
            return __('reviews.skipped.turned_off');
        }

        if ($request->google_profile_id === null && $this->profile($job) === null) {
            return __('reviews.skipped.no_profile');
        }

        $recent = ReviewRequest::query()
            ->where('customer_id', $request->customer_id)
            ->where('status', ReviewRequest::SENT)
            ->where('sent_at', '>=', now()->subDays($company->review_request_cooldown_days))
            ->when($request->exists, fn ($q) => $q->whereKeyNot($request->id))
            ->exists();

        return $recent ? __('reviews.skipped.recent', ['days' => $company->review_request_cooldown_days]) : null;
    }
}
