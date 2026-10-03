<?php

namespace App\Support\Jobs;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Unfinished work has no date cutoff. Each job belongs to exactly one reason bucket,
 * regardless of how many visits it has. Tenant and member scopes apply at every layer.
 */
class JobBacklog
{
    public const REASONS = ['overdue', 'needs_schedule', 'waiting_for_parts', 'waiting_for_customer', 'on_hold', 'scheduled'];

    private const REASON_SQL = "CASE
        WHEN status = 'waiting_for_parts' THEN 'waiting_for_parts'
        WHEN status = 'waiting_for_customer' THEN 'waiting_for_customer'
        WHEN status = 'on_hold' THEN 'on_hold'
        WHEN has_overdue_visit AND NOT has_upcoming_visit THEN 'overdue'
        WHEN NOT has_open_visit THEN 'needs_schedule'
        ELSE 'scheduled' END";

    /**
     * @return Builder<ServiceJob>
     */
    public static function query(User $user): Builder
    {
        $now = CarbonImmutable::now()->utc();
        $open = fn (Builder $visits) => $visits->whereIn('status', VisitStatus::openValues());
        $facts = ServiceJob::query()
            ->visibleTo($user)
            ->whereNotIn('status', [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled])
            ->whereNull('outcome')
            ->whereNull('closed_at')
            ->select('service_jobs.*')
            ->withExists([
                'visits as has_open_visit' => $open,
                'visits as has_overdue_visit' => fn (Builder $visits) => $open($visits)->where('scheduled_end', '<', $now),
                'visits as has_upcoming_visit' => fn (Builder $visits) => $open($visits)->where('scheduled_end', '>=', $now),
            ]);

        $classified = ServiceJob::query()
            ->fromSub($facts, 'service_jobs')
            ->select('service_jobs.*')
            ->selectRaw(self::REASON_SQL.' as backlog_reason');

        // The outer layer makes the reason available for filtering, sorting and grouping.
        return ServiceJob::query()->fromSub($classified, 'service_jobs')->select('service_jobs.*');
    }

    /**
     * One aggregate query; never loads the entire queue into memory.
     *
     * @return array{total: int, counts: array<string, int>}
     */
    public static function summary(User $user): array
    {
        $counts = array_fill_keys(self::REASONS, 0);
        $groups = self::query($user)
            ->select('backlog_reason')
            ->selectRaw('count(*) as job_count')
            ->groupBy('backlog_reason')
            ->get();

        foreach ($groups as $group) {
            $counts[$group->backlog_reason] = (int) $group->job_count;
        }

        return ['total' => array_sum($counts), 'counts' => $counts];
    }
}
