<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Models\GoogleProfile;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Google review requests (SPEC §8). When a job with "Ask for a review" is paid in full, a request is scheduled after
 * the company's delay and sent by SMS (Automatic mode) or email. One request per job; none if the customer already
 * got one within the company's cooldown period. Every customer gets the same request: no incentive, no
 * "are you happy?" filter. Must run in a tenant context.
 */
class ReviewRequests
{
    public function __construct(private readonly Messenger $messenger) {}

    /**
     * Called when the job becomes paid.
     */
    public function schedule(ServiceJob $job): ?ReviewRequest
    {
        if (! $job->ask_for_review || ReviewRequest::query()->where('service_job_id', $job->id)->exists()) {
            return null;
        }

        $company = currentCompany();
        $request = new ReviewRequest([
            'customer_id' => $job->customer_id,
            'service_job_id' => $job->id,
            'google_profile_id' => $this->profile($job)?->id,
            'status' => ReviewRequest::SCHEDULED,
            'send_after' => now()->addHours($company->review_request_delay_hours),
        ]);

        $skip = $this->skipReason($request);
        if ($skip !== null) {
            $request->fill(['status' => ReviewRequest::SKIPPED, 'skip_reason' => $skip, 'send_after' => null]);
        }

        $request->save();

        return $request;
    }

    /**
     * Sends a scheduled request that is due (minute command).
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
        $message = $this->messenger->send(MessageKind::ReviewRequest, $job->customer, $job, $this->text($job, $request->profile));

        $request->update([
            'status' => $message->status === 'blocked' ? ReviewRequest::SKIPPED : ReviewRequest::SENT,
            'skip_reason' => $message->status === 'blocked' ? $message->status_reason : null,
            'channel' => $message->channel,
            'sent_at' => $message->status === 'blocked' ? null : now(),
            'message_id' => $message->id,
        ]);
    }

    /**
     * From technician's phone: the request is sent by the technician (sms: link) and recorded as sent.
     */
    public function sentFromPhone(ServiceJob $job, string $to, User $user): void
    {
        $profile = $this->profile($job);
        $message = $this->messenger->openedOnPhone(MessageKind::ReviewRequest, $job->customer, $job, $to, $this->text($job, $profile), $user);

        ReviewRequest::query()->updateOrCreate(['service_job_id' => $job->id], [
            'customer_id' => $job->customer_id,
            'google_profile_id' => $profile?->id,
            'status' => ReviewRequest::SENT,
            'channel' => $message->channel,
            'skip_reason' => null,
            'sent_at' => now(),
            'message_id' => $message->id,
        ]);
    }

    public function text(ServiceJob $job, ?GoogleProfile $profile): string
    {
        return MessageTemplates::render(currentCompany(), MessageKind::ReviewRequest, [
            ...MessageContext::for($job->customer, $job),
            'review_link' => $profile?->review_url,
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

    /**
     * Whether the technician's "Send review request" button is offered on the job.
     */
    public static function phoneButton(ServiceJob $job): bool
    {
        return currentCompany()->sms_mode === SmsMode::TechnicianPhone;
    }
}
