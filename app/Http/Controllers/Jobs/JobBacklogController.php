<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Support\Jobs\JobBacklog;
use App\Support\Jobs\JobPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JobBacklogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewMine', ServiceJob::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'reason' => in_array($request->query('reason'), JobBacklog::REASONS, true) ? $request->query('reason') : '',
        ];

        $jobs = JobBacklog::query($request->user())
            ->search($filters['search'])
            ->when($filters['reason'] !== '', fn ($query) => $query->where('backlog_reason', $filters['reason']))
            ->with(['customer', 'property', 'brand', 'appliances', 'visits.assignees'])
            ->orderByRaw("CASE backlog_reason WHEN 'overdue' THEN 0 WHEN 'needs_schedule' THEN 1 WHEN 'waiting_for_parts' THEN 2 WHEN 'waiting_for_customer' THEN 3 WHEN 'on_hold' THEN 4 ELSE 5 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceJob $job) => [
                ...JobPresenter::row($job, $job->visits->first(fn (JobVisit $visit) => $visit->status->isOpen() && $visit->scheduled_end->gte(now()))),
                'backlog_reason' => $job->backlog_reason,
                'backlog_reason_label' => __('jobs.backlog.reasons.'.$job->backlog_reason),
            ]);

        return Inertia::render('jobs/backlog', ['jobs' => $jobs, 'filters' => $filters]);
    }
}
