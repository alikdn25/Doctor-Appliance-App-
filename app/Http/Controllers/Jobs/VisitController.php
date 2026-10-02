<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\SaveVisit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\VisitRequest;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Scheduling visits of a job (office).
 */
class VisitController extends Controller
{
    public function store(VisitRequest $request, ServiceJob $job, SaveVisit $save): RedirectResponse
    {
        $visit = $request->visit();
        $save->handle($job, null, $visit['attributes'], $visit['assignee_ids'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_created')]);

        return to_route('jobs.show', $job);
    }

    public function update(VisitRequest $request, JobVisit $visit, SaveVisit $save): RedirectResponse
    {
        $data = $request->visit();
        $save->handle($visit->job, $visit, $data['attributes'], $data['assignee_ids'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_updated')]);

        return to_route('jobs.show', $visit->service_job_id);
    }

    public function destroy(Request $request, JobVisit $visit, SaveVisit $save): RedirectResponse
    {
        Gate::authorize('delete', $visit);

        $save->delete($visit, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_deleted')]);

        return to_route('jobs.show', $visit->service_job_id);
    }
}
