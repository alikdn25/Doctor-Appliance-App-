<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Support\Jobs\JobBacklog;
use App\Support\Jobs\JobPresenter;
use Carbon\CarbonImmutable;
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
            ->addSelect(['status_since' => JobStatusChange::query()->select('created_at')
                ->whereColumn('service_job_id', 'service_jobs.id')->latest('created_at')->limit(1)])
            ->orderByRaw("CASE backlog_reason WHEN 'overdue' THEN 0 WHEN 'parts_to_order' THEN 1 WHEN 'needs_schedule' THEN 2 WHEN 'waiting_for_parts' THEN 3 WHEN 'waiting_for_customer' THEN 4 WHEN 'on_hold' THEN 5 ELSE 6 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceJob $job) => [
                ...JobPresenter::row($job, $job->visits->first(fn (JobVisit $visit) => $visit->status->isOpen() && $visit->scheduled_end->gte(now()))),
                'backlog_reason' => $job->backlog_reason,
                'backlog_reason_label' => self::label($job),
            ]);

        return Inertia::render('jobs/backlog', ['jobs' => $jobs, 'filters' => $filters]);
    }

    /** Waiting reasons show how long the job has been in that state: parts can take weeks or months. */
    private static function label(ServiceJob $job): string
    {
        $label = __('jobs.backlog.reasons.'.$job->backlog_reason);
        $since = $job->status_since ?? $job->created_at;
        if (! in_array($job->backlog_reason, ['parts_to_order', 'waiting_for_parts', 'waiting_for_customer', 'on_hold'], true) || $since === null) {
            return $label;
        }

        return __('jobs.backlog.waiting_days', ['reason' => $label, 'days' => (int) CarbonImmutable::parse($since)->diffInDays(now())]);
    }
}
