<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Enums\SmsMode;
use App\Enums\VisitStatus;
use App\Jobs\SendOfficeSms;
use App\Messaging\Messenger;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\SmsAccount;
use App\Models\User;
use App\Notifications\StrictArrivalReminder;
use App\Sms\SmsProvider;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use IntlDateFormatter;

/**
 * Every 5 minutes: the people assigned to a visit with a strict arrival time are reminded (email, plus a text in
 * Automatic SMS mode) shortly before it starts — the company's "remind before" minutes. Each visit once.
 * Staff reminders are work alerts, so the customers' quiet hours do not hold them back.
 */
#[Signature('visits:strict-arrival-reminders')]
#[Description('Remind technicians before visits with a strict arrival time')]
class SendStrictArrivalReminders extends Command
{
    public function handle(CurrentCompany $tenancy, SmsProvider $sms, Messenger $messenger): int
    {
        Company::query()->where('status', 'active')->each(function (Company $company) use ($tenancy, $sms, $messenger) {
            $tenancy->runAs($company, function () use ($company, $sms, $messenger) {
                $texts = $company->sms_mode === SmsMode::Automatic && $sms->isConfigured()
                    && SmsAccount::query()->whereNotNull('phone_number')->exists();

                JobVisit::query()
                    ->with(['job.customer', 'job.property', 'assignees'])
                    ->where('strict_arrival', true)
                    ->where('status', VisitStatus::Scheduled->value)
                    ->whereNull('strict_reminder_sent_at')
                    ->whereBetween('scheduled_start', [now(), now()->addMinutes($company->strict_arrival_reminder_minutes)])
                    ->whereHas('job', fn ($q) => $q->whereNotIn('status', [JobStatus::Cancelled->value, JobStatus::OnHold->value]))
                    ->each(function (JobVisit $visit) use ($company, $texts, $messenger) {
                        $job = $visit->job;
                        $details = [
                            'number' => (string) $job->number,
                            'customer' => (string) $job->customer?->display_name,
                            'address' => (string) $job->property?->fullAddress(),
                            'window' => $this->time($company, $visit->scheduled_start).' - '.$this->time($company, $visit->scheduled_end),
                            'time' => $this->time($company, $visit->scheduled_start),
                        ];

                        $visit->assignees->each(function (User $user) use ($company, $texts, $messenger, $details, $job) {
                            $user->notify(new StrictArrivalReminder($details, route('jobs.show', $job)));
                            $to = PhoneNumber::normalize($user->phone, $company->country);

                            if ($texts && $to !== '' && ! $messenger->registrationBlocks($to)) {
                                SendOfficeSms::dispatch($company->id, $to, __('jobs.strict.reminder', $details));
                            }
                        });

                        $visit->forceFill(['strict_reminder_sent_at' => now()])->save();
                    });
            });
        });

        return self::SUCCESS;
    }

    private function time(Company $company, \DateTimeInterface $at): string
    {
        $formatter = new IntlDateFormatter(str_replace('-', '_', $company->locale), IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $company->timezone);

        return (string) $formatter->format($at);
    }
}
