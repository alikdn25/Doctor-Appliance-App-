<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SmsAccount;
use App\Sms\SmsProvider;
use App\Support\Jobs\MorningSms;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute: the morning SMS to the owner's own phone (owner's task of November 19) at the company's chosen time
 * (06:30 by default), once a day, from the company's SMS number. It goes to the owner's own phone, so the quiet hours
 * and the customer opt-outs do not apply, and it is not stored in the customer chats. More than three hours late it
 * is skipped rather than sent stale.
 */
#[Signature('summary:morning-sms')]
#[Description('Send the morning SMS to the owner\'s own phone')]
class SendMorningSms extends Command
{
    public function handle(CurrentCompany $tenancy, SmsProvider $sms): int
    {
        $count = 0;

        Company::query()->where('status', 'active')->where('morning_sms_enabled', true)->whereNotNull('morning_sms_phone')
            ->each(function (Company $company) use ($tenancy, $sms, &$count) {
                $now = CarbonImmutable::now($company->timezone);
                $at = $now->setTimeFromTimeString($company->morning_sms_time ?: '06:30');
                $today = $now->toDateString();

                if ($now->lessThan($at) || $company->morning_sms_sent_on?->toDateString() === $today) {
                    return;
                }

                $company->forceFill(['morning_sms_sent_on' => $today])->save();
                if ($now->greaterThan($at->addHours(3))) {
                    return;
                }

                $tenancy->runAs($company, function () use ($company, $sms, $now, &$count) {
                    $account = SmsAccount::query()->whereNotNull('phone_number')->first();
                    if ($account === null || ! $sms->isConfigured()) {
                        Log::info('Morning SMS skipped: SMS is not set up', ['company' => $company->id]);

                        return;
                    }

                    try {
                        $sms->send($account, (string) $company->morning_sms_phone, MorningSms::build($company, $now, markReported: true));
                        $count++;
                    } catch (Throwable $e) {
                        Log::warning('Morning SMS failed', ['company' => $company->id, 'error' => $e->getMessage()]);
                    }
                });
            });

        $this->info("Morning SMS: {$count}");

        return self::SUCCESS;
    }
}
