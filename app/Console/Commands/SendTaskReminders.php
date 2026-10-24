<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\Task;
use App\Notifications\AppAlert;
use App\Support\Locale\LocalTime;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use IntlDateFormatter;

/**
 * Every minute: tasks whose reminder time has come are sent to the one they are for — push on the phones they turned
 * it on for and email (once delivery is on), plus the bell. Each task once; a task done before then is skipped, and
 * one more than a day late is dropped rather than sent stale.
 */
#[Signature('tasks:send-reminders')]
#[Description('Send task reminders')]
class SendTaskReminders extends Command
{
    public function handle(CurrentCompany $tenancy): int
    {
        Company::query()->where('status', 'active')->each(function (Company $company) use ($tenancy) {
            $tenancy->runAs($company, function () use ($company) {
                Task::query()
                    ->with('user')
                    ->whereNotNull('remind_at')
                    ->whereNull('reminded_at')
                    ->whereNull('completed_at')
                    ->where('remind_at', '<=', now())
                    ->each(function (Task $task) use ($company) {
                        $task->forceFill(['reminded_at' => now()])->saveQuietly();

                        if ($task->remind_at->lt(now()->subDay()) || $task->user === null) {
                            return;
                        }

                        $when = $task->all_day
                            ? __('tasks.all_day')
                            : LocalTime::format($task->starts_at, $company->timezone, $company->locale);
                        $day = LocalTime::format($task->task_date->copy()->setTimeFromTimeString('12:00'), 'UTC', $company->locale,
                            IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'EEE, MMM d');

                        $task->user->notify(new AppAlert(
                            'task_reminder',
                            __('tasks.reminder_title', ['title' => $task->title]),
                            trim(__('tasks.reminder_body', ['day' => $day, 'time' => $when])."\n".($task->note ?? '')),
                            $task->user->can('dispatch', ServiceJob::class)
                                ? route('calendar', ['view' => 'day', 'date' => $task->task_date->format('Y-m-d')])
                                : route('jobs.mine', ['date' => $task->task_date->format('Y-m-d')]),
                        ));
                    });
            });
        });

        return self::SUCCESS;
    }
}
