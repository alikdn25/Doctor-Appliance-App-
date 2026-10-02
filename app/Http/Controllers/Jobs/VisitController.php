<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\SaveVisit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\VisitRequest;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

        // The calendar schedules visits without leaving the page.
        return $request->boolean('back') ? back() : to_route('jobs.show', $job);
    }

    public function update(VisitRequest $request, JobVisit $visit, SaveVisit $save): RedirectResponse
    {
        $data = $request->visit();
        $save->handle($visit->job, $visit, $data['attributes'], $data['assignee_ids'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_updated')]);

        return $request->boolean('back') ? back() : to_route('jobs.show', $visit->service_job_id);
    }

    /**
     * Drag and drop on the calendar.
     */
    public function move(Request $request, JobVisit $visit, SaveVisit $save): RedirectResponse
    {
        Gate::authorize('update', $visit);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'from_user_id' => ['nullable', 'integer'],
            'to_user_id' => ['nullable', 'integer'],
        ]);

        $to = isset($validated['to_user_id']) ? (int) $validated['to_user_id'] : null;

        if ($to !== null && ! Membership::query()->assignable()->where('user_id', $to)->exists()) {
            throw ValidationException::withMessages(['to_user_id' => __('jobs.errors.invalid_assignee')]);
        }

        $start = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            "{$validated['date']} {$validated['start_time']}",
            currentCompany()->timezone,
        );

        $save->move(
            $visit,
            $start,
            isset($validated['from_user_id']) ? (int) $validated['from_user_id'] : null,
            $to,
            $request->user(),
        );

        return back();
    }

    public function destroy(Request $request, JobVisit $visit, SaveVisit $save): RedirectResponse
    {
        Gate::authorize('delete', $visit);

        $save->delete($visit, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_deleted')]);

        return to_route('jobs.show', $visit->service_job_id);
    }
}
