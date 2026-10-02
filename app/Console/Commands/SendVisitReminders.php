<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\VisitStatus;
use App\Messaging\MessageContext;
use App\Messaging\MessageTemplates;
use App\Messaging\Messenger;
use App\Models\Company;
use App\Models\JobVisit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hourly: at the reminder hour of each company (config sms.reminder_hour, company time), remind the customers of
 * tomorrow's visits — by SMS in Automatic mode, otherwise by email. Each visit is reminded once.
 */
#[Signature('messages:send-visit-reminders {--force : Ignore the reminder hour}')]
#[Description("Remind customers of tomorrow's visits")]
class SendVisitReminders extends Command
{
    public function handle(Messenger $messenger, CurrentCompany $tenancy): int
    {
        Company::query()->where('status', 'active')->each(function (Company $company) use ($messenger, $tenancy) {
            $local = CarbonImmutable::now($company->timezone);

            if (! $this->option('force') && $local->hour !== (int) config('sms.reminder_hour')) {
                return;
            }

            $tenancy->runAs($company, function () use ($messenger, $local) {
                $tomorrow = $local->addDay()->startOfDay();

                JobVisit::query()
                    ->with(['job.customer', 'job.brand', 'assignees'])
                    ->where('status', VisitStatus::Scheduled->value)
                    ->whereNull('reminder_sent_at')
                    ->whereBetween('scheduled_start', [$tomorrow->utc(), $tomorrow->endOfDay()->utc()])
                    ->whereHas('job', fn ($q) => $q->whereNotIn('status', [JobStatus::Cancelled->value, JobStatus::OnHold->value]))
                    ->each(function (JobVisit $visit) use ($messenger) {
                        $job = $visit->job;
                        $body = MessageTemplates::render(currentCompany(), MessageKind::VisitReminder,
                            MessageContext::for($job->customer, $job, $visit, $visit->assignees->first()));

                        $messenger->send(MessageKind::VisitReminder, $job->customer, $job, $body, null,
                            __('messages.email_subject.visit_reminder', ['brand' => $job->brand?->name ?? currentCompany()->name]));
                        $visit->forceFill(['reminder_sent_at' => now()])->save();
                    });
            });
        });

        return self::SUCCESS;
    }
}
