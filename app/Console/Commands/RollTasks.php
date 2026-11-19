<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Task;
use App\Support\Parts\SupplierTasks;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every 15 minutes, per company and in its time zone (owner's task of November 19):
 * - undone tasks of past days move to today, keeping the day they were first planned ("carried over N days");
 * - a repeating task (weekly / monthly) makes its next one when that day comes (once per task);
 * - the automatic "Buy at {supplier}" tasks follow the Order list.
 * Code only; nothing is deleted.
 */
#[Signature('tasks:roll')]
#[Description('Carry undone tasks over to today and make the next repeating tasks')]
class RollTasks extends Command
{
    public function handle(CurrentCompany $tenancy): int
    {
        Company::query()->where('status', 'active')->each(function (Company $company) use ($tenancy) {
            $tenancy->runAs($company, fn () => self::roll($company, CarbonImmutable::now()));
        });

        return self::SUCCESS;
    }

    public static function roll(Company $company, CarbonImmutable $now): void
    {
        $today = $now->setTimezone($company->timezone)->startOfDay();
        $day = $today->toDateString();

        // The next one of a series first, from the day the task was planned for.
        Task::query()->where('repeat', '!=', 'none')->whereNull('repeated_at')->each(function (Task $task) use ($today) {
            $base = CarbonImmutable::parse(($task->planned_date ?? $task->task_date)->format('Y-m-d'), $today->timezone);
            $step = fn (CarbonImmutable $d) => $task->repeat === 'weekly' ? $d->addWeek() : $d->addMonthNoOverflow();
            $next = $step($base);

            if ($next->greaterThan($today)) {
                return;
            }
            // Days missed while nobody looked: one task, on the latest of them.
            while ($step($next)->lessThanOrEqualTo($today)) {
                $next = $step($next);
            }

            $copy = $task->replicate(['starts_at', 'ends_at', 'remind_at', 'reminded_at', 'completed_at', 'repeated_at', 'planned_date']);
            $copy->task_date = $next->toDateString();
            $copy->save();
            $task->forceFill(['repeated_at' => now()])->save();
        });

        Task::query()->whereNull('completed_at')->where('task_date', '<', $day)->each(function (Task $task) use ($day) {
            $task->planned_date ??= $task->task_date->format('Y-m-d');
            $task->task_date = $day;
            $task->save();
        });

        SupplierTasks::sync();
    }
}
