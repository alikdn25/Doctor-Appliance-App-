<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Messaging\Messenger;
use App\Messaging\ReviewRequests;
use App\Models\Company;
use App\Models\GoogleProfile;
use App\Models\Message;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Support\Reviews\ReviewTracking;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * The one Google review reminder (owner's task of November 25): only on jobs where a review was requested by hand
 * (the review request, or "Request review" on the Paid screen). 24 hours after the job was paid in full (and after
 * the request) a separate email with the same text, picture and location goes to the customer, but only when nobody
 * clicked a review link of that job in any email. Once per job; email only; no other reminders. The wait is
 * fieldservice.review_reminder_minutes (REVIEW_REMINDER_DELAY_MINUTES) for tests.
 */
#[Signature('reviews:send-reminders')]
#[Description('Send the one review reminder a day after a job was paid, when no review link was clicked')]
class SendReviewReminders extends Command
{
    public function handle(CurrentCompany $tenancy, Messenger $messenger): int
    {
        $due = CarbonImmutable::now()->subMinutes((int) config('fieldservice.review_reminder_minutes'));
        $count = 0;
        $companyIds = ReviewRequest::withoutCompanyScope()->where('status', ReviewRequest::SENT)->whereNull('reminder_sent_at')
            ->where('sent_at', '<=', $due)->distinct()->pluck('company_id');

        foreach (Company::query()->whereIn('id', $companyIds)->where('status', 'active')->get() as $company) {
            $tenancy->runAs($company, function () use ($due, $messenger, &$count) {
                $requests = ReviewRequest::query()->with(['customer', 'profile'])
                    ->where('status', ReviewRequest::SENT)->whereNull('reminder_sent_at')->where('sent_at', '<=', $due)
                    ->whereHas('job', fn ($q) => $q->where('status', JobStatus::Paid->value)->whereNull('review_clicked_at')->whereNull('deleted_at'))
                    ->get();

                foreach ($requests as $request) {
                    $job = ServiceJob::query()->with('brand')->find($request->service_job_id);
                    if ($job === null || ! self::paidBefore($job, $due)) {
                        continue;
                    }

                    try {
                        if ($this->send($request, $job, $messenger)) {
                            $count++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        $this->info("Review reminders sent: {$count}");

        return self::SUCCESS;
    }

    /**
     * Paid in full, and the last invoice was paid before $due.
     */
    private static function paidBefore(ServiceJob $job, CarbonImmutable $due): bool
    {
        $paidAt = $job->invoices()->where('status', '!=', InvoiceStatus::Void->value)->max('paid_at');

        return $paidAt !== null && CarbonImmutable::parse($paidAt)->lessThanOrEqualTo($due);
    }

    private function send(ReviewRequest $request, ServiceJob $job, Messenger $messenger): bool
    {
        $profile = $request->profile ?? GoogleProfile::query()->find($job->brand?->google_profile_id);
        $customer = $request->customer;
        $original = $request->message_id === null ? null : Message::query()->find($request->message_id);
        $email = $original?->to ?? $customer?->primaryEmail()->value('email');

        // Marked first: whatever happens, the reminder goes at most once.
        $request->forceFill(['reminder_sent_at' => now()])->save();

        if ($profile === null || blank($profile->review_url) || $customer === null || blank($email)) {
            return false;
        }

        // The same text as the request (the one sent by email, else the ready text), links through our address.
        $body = ReviewTracking::inText($original->body ?? ReviewRequests::text($customer, $job->brand?->name, $profile), $job, $profile);
        $messenger->emailToAddress(MessageKind::ReviewRequest, $customer, $job, $email, $body, null, reviewUrl: ReviewTracking::link($job, $profile));

        return true;
    }
}
