<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Membership;
use App\Notifications\AppAlert;
use App\Support\Jobs\MorningSummary;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The morning summary (owner's task of November 18): at each person's time (07:00 by default, Settings →
 * Notifications, on/off), a push and a notification with the day at a glance, opening the "Today" screen. Once a day;
 * more than three hours late it is skipped rather than sent stale. Code only, no AI.
 */
#[Signature('summary:morning')]
#[Description('Send each person their morning summary')]
class SendMorningSummaries extends Command
{
    public function handle(CurrentCompany $tenancy): int
    {
        $count = 0;

        Company::query()->where('status', 'active')->each(function (Company $company) use ($tenancy, &$count) {
            $now = CarbonImmutable::now($company->timezone);

            $tenancy->runAs($company, function () use ($now, &$count) {
                $today = $now->toDateString();
                $members = Membership::query()->with('user')->where('is_active', true)->where('morning_summary_enabled', true)
                    ->where(fn ($q) => $q->whereNull('morning_summary_sent_on')->orWhere('morning_summary_sent_on', '<', $today))->get();

                foreach ($members as $member) {
                    $at = $now->setTimeFromTimeString($member->morning_summary_time ?: '07:00');
                    if ($member->user === null || ! $member->user->is_active || $now->lessThan($at)) {
                        continue;
                    }

                    $member->forceFill(['morning_summary_sent_on' => $today])->save();
                    if ($now->greaterThan($at->addHours(3))) {
                        continue;
                    }

                    try {
                        $summary = MorningSummary::for($member->user, $now->startOfDay());
                        $member->user->notify(new AppAlert('morning_summary', __('automation.today.push_title'),
                            MorningSummary::line($summary), route('today'), mail: false));
                        $count++;
                    } catch (Throwable $e) {
                        Log::warning('Morning summary failed', ['user' => $member->user_id, 'error' => $e->getMessage()]);
                    }
                }
            });
        });

        $this->info("Morning summaries: {$count}");

        return self::SUCCESS;
    }
}
