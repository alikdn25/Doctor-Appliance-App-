<?php

namespace App\Console\Commands;

use App\Enums\EstimateStatus;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Messaging\MessageContext;
use App\Messaging\MessageTemplates;
use App\Messaging\Messenger;
use App\Models\Company;
use App\Models\Estimate;
use App\Support\Billing\Money;
use App\Support\Billing\PublicDocument;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('estimates:send-followups {--force : Ignore the reminder hour}')]
#[Description('Send one follow-up for unanswered estimates when enabled by the company')]
class SendEstimateFollowups extends Command
{
    public function handle(Messenger $messenger, CurrentCompany $tenancy): int
    {
        Company::query()->where('status', 'active')->whereNotNull('estimate_followup_days')->each(function (Company $company) use ($messenger, $tenancy) {
            $local = CarbonImmutable::now($company->timezone);
            if (! $this->option('force') && $local->hour !== (int) config('sms.reminder_hour')) {
                return;
            }

            $tenancy->runAs($company, function () use ($company, $local, $messenger) {
                $this->due($local, $company->estimate_followup_days)->eachById(function (Estimate $candidate) use ($company, $local, $messenger) {
                    DB::transaction(function () use ($candidate, $company, $local, $messenger) {
                        // Lock and recheck, so concurrent scheduler runs cannot enqueue twice.
                        $estimate = $this->due($local, $company->estimate_followup_days)->whereKey($candidate->id)->lockForUpdate()->first();
                        if ($estimate === null) {
                            return;
                        }
                        $job = $estimate->job()->lockForUpdate()->first();
                        if ($job === null || $job->closed_at !== null || in_array($job->status, [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled, JobStatus::OnHold], true)) {
                            return;
                        }
                        $job->load(['customer', 'brand']);
                        if ($job->customer === null) {
                            return;
                        }
                        $body = MessageTemplates::render($company, MessageKind::EstimateFollowup, [
                            ...MessageContext::for($job->customer, $job),
                            'number' => $estimate->number,
                            'amount' => Money::format($estimate->total, $estimate->currency, $company->locale),
                            'link' => PublicDocument::url($estimate),
                        ]);
                        $messenger->send(MessageKind::EstimateFollowup, $job->customer, $job, $body,
                            emailSubject: __('messages.email_subject.estimate_followup', ['brand' => $job->brand?->name ?? $company->name, 'number' => $estimate->number]),
                            afterCommit: true);
                        // Also stamp a blocked attempt; its reason remains in message history.
                        $estimate->forceFill(['followup_processed_at' => now()])->saveQuietly();
                    });
                }, 100);
            });
        });

        return self::SUCCESS;
    }

    /** @return Builder<Estimate> */
    private function due(CarbonImmutable $local, int $days): Builder
    {
        return Estimate::query()->where('status', EstimateStatus::Draft->value)
            ->whereNotNull('sent_at')->where('sent_at', '<=', $local->subDays($days)->utc())
            ->whereNull('followup_processed_at')
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $local->toDateString()));
    }
}
