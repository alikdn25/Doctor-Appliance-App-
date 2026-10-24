<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\SaveVisit;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
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
        $saved = $save->handle($job, null, $visit['attributes'], $visit['assignee_ids'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_created')]);
        self::askToNotify($saved, null);

        // The calendar schedules visits without leaving the page.
        return $request->boolean('back') ? back() : to_route('jobs.show', $job);
    }

    public function update(VisitRequest $request, JobVisit $visit, SaveVisit $save): RedirectResponse
    {
        $data = $request->visit();
        $was = [$visit->scheduled_start, $visit->scheduled_end];
        $save->handle($visit->job, $visit, $data['attributes'], $data['assignee_ids'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_updated')]);
        self::askToNotify($visit, $was);

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

        $was = [$visit->scheduled_start, $visit->scheduled_end];
        $moved = $save->move(
            $visit,
            $start,
            isset($validated['from_user_id']) ? (int) $validated['from_user_id'] : null,
            $to,
            $request->user(),
        );
        self::askToNotify($moved, $was);

        return back();
    }

    /**
     * A finished booking got a new day or arrival window (or a new time for the same job): the page asks "Notify
     * client?" and Yes sends the updated confirmation. Drafts, closed jobs and times already gone ask nothing.
     *
     * @param  array{0: mixed, 1: mixed}|null  $was  The time before the change; null for a new visit.
     */
    private static function askToNotify(JobVisit $visit, ?array $was): void
    {
        $visit->refresh();
        $job = $visit->job;
        $changed = $was === null
            || ! $visit->scheduled_start->equalTo($was[0])
            || ! $visit->scheduled_end->equalTo($was[1]);

        if ($changed
            && $job->status !== JobStatus::Draft
            && $job->status->allowsVisitWork()
            && $visit->status === VisitStatus::Scheduled
            && $visit->scheduled_end->isFuture()) {
            Inertia::flash('notify_client', ['job_id' => $job->id, 'number' => $job->number]);
        }
    }

    public function destroy(Request $request, JobVisit $visit, SaveVisit $save): RedirectResponse
    {
        Gate::authorize('delete', $visit);

        $save->delete($visit, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.visit_deleted')]);

        return to_route('jobs.show', $visit->service_job_id);
    }
}
