<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\SaveVisit;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\VisitRequest;
use App\Messaging\BookingConfirmation;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Support\Ui\SavedToast;
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
    public function store(VisitRequest $request, ServiceJob $job, SaveVisit $save, BookingConfirmation $confirmation): RedirectResponse
    {
        $visit = $request->visit();

        // No time (owner's task of November 25): the day is kept on the job and it shows under No time that day.
        if ($visit === null) {
            if ($request->filled('date')) {
                $job->forceFill(['planned_date' => $request->validated('date')])->save();
            }
            SavedToast::flash(route('jobs.show', $job));

            return $request->boolean('back') ? back() : to_route('jobs.show', $job);
        }

        $saved = $save->handle($job, null, $visit['attributes'], $visit['assignee_ids'], $request->user());

        SavedToast::flash(route('jobs.show', $job), __('jobs.visit_created'));
        self::notify($request, $saved, $confirmation);

        // The calendar schedules visits without leaving the page.
        return $request->boolean('back') ? back() : to_route('jobs.show', $job);
    }

    public function update(VisitRequest $request, JobVisit $visit, SaveVisit $save, BookingConfirmation $confirmation): RedirectResponse
    {
        $data = $request->visit() ?? self::keepTimes($request, $visit);
        $save->handle($visit->job, $visit, $data['attributes'], $data['assignee_ids'], $request->user());

        SavedToast::flash(route('jobs.show', $visit->service_job_id), __('jobs.visit_updated'));
        self::notify($request, $visit, $confirmation);

        return $request->boolean('back') ? back() : to_route('jobs.show', $visit->service_job_id);
    }

    /**
     * A visit saved with its time emptied keeps its times (on the day picked, if one was).
     *
     * @return array{attributes: array<string, mixed>, assignee_ids: list<int>}
     */
    private static function keepTimes(VisitRequest $request, JobVisit $visit): array
    {
        $timezone = currentCompany()->timezone;
        $start = CarbonImmutable::instance($visit->scheduled_start)->setTimezone($timezone);
        $end = CarbonImmutable::instance($visit->scheduled_end)->setTimezone($timezone);

        return VisitRequest::toVisit([
            'date' => $request->validated('date') ?: $start->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'estimated_duration_minutes' => $request->validated('estimated_duration_minutes') ?? $visit->estimated_duration_minutes,
            'strict_arrival' => $request->validated('strict_arrival') ?? $visit->strict_arrival,
            'assignee_ids' => $request->validated('assignee_ids') ?? $visit->assignees()->pluck('users.id')->all(),
        ]) ?? throw new \LogicException('A visit always has a start.');
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

        // Dragging never texts the customer: "Notify client" is a box in the visit's sheet.
        return back();
    }

    /**
     * "Notify client" ticked in the visit's sheet: the updated confirmation goes now. Unticked, nothing is sent.
     * Drafts never send (Finish booking does).
     */
    private static function notify(Request $request, JobVisit $visit, BookingConfirmation $confirmation): void
    {
        $job = $visit->job()->firstOrFail();

        if (! $request->boolean('notify') || $job->status === JobStatus::Draft) {
            return;
        }

        $sent = $confirmation->send($job, $request->user());
        if ($sent['channel'] === BookingConfirmation::PHONE) {
            JobController::flashBooked($job, $visit->refresh(), $sent);
        } else {
            BookingConfirmationController::flashResult($sent);
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
