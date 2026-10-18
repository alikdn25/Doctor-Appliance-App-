<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Jobs\JobBacklog;
use App\Support\Jobs\JobPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Not completed jobs: the Open tab of Jobs (UX-REDESIGN §7.4). The old address opens that tab.
 */
class JobBacklogController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Gate::authorize('viewMine', ServiceJob::class);

        return to_route('jobs.index', array_filter([
            'tab' => 'open',
            'reason' => $request->query('reason'),
            'search' => $request->query('search'),
        ]));
    }

    /**
     * Unfinished work by urgency: overdue → needs scheduling → waiting → on hold → scheduled (nearest visit first);
     * or, from "⋯", the newest jobs first.
     *
     * @param  array{search: string, reason: string, sort: string}  $filters
     */
    public static function jobs(User $user, array $filters): LengthAwarePaginator
    {
        $nextVisit = "(select min(v.scheduled_start) from job_visits v where v.service_job_id = service_jobs.id and v.status in ('scheduled', 'on_the_way', 'in_progress') and v.scheduled_end >= ?)";

        return JobBacklog::query($user)
            ->search($filters['search'])
            ->when($filters['reason'] !== '', fn ($query) => $query->where('backlog_reason', $filters['reason']))
            ->with(['customer.primaryPhone', 'property', 'brand', 'appliances', 'visits.assignees'])
            ->when(Gate::forUser($user)->allows('viewAny', Invoice::class), fn ($q) => $q->with('invoices'))
            ->when(
                $filters['sort'] === 'newest',
                fn ($query) => $query->orderByDesc('id'),
                fn ($query) => $query
                    ->orderByRaw("CASE backlog_reason WHEN 'overdue' THEN 0 WHEN 'needs_schedule' THEN 1 WHEN 'waiting_for_parts' THEN 2 WHEN 'waiting_for_customer' THEN 3 WHEN 'on_hold' THEN 4 ELSE 5 END")
                    ->orderByRaw("{$nextVisit} asc nulls last", [now()->utc()])
                    ->orderBy('created_at')
                    ->orderBy('id'),
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceJob $job) => [
                ...JobPresenter::row($job, $job->visits->first(fn (JobVisit $visit) => $visit->status->isOpen() && $visit->scheduled_end->gte(now()))),
                'backlog_reason' => $job->backlog_reason,
                'backlog_reason_label' => __('jobs.backlog.reasons.'.$job->backlog_reason),
            ]);
    }
}
