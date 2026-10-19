<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\AssignJob;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Choosing the technician of a job in one tap, and "Transfer" of several jobs to another person (office only).
 */
class AssignmentController extends Controller
{
    /**
     * The people who can take a job and their work on a day, so the picker can say "3 jobs · free" or
     * "busy 11–1" for the job's window.
     */
    public function load(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', ServiceJob::class);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['technicians' => self::technicians($data['date'])]);
    }

    /**
     * @return list<array{id: int, name: string, visits: list<array{id: int, job_id: int, start: string, end: string}>}>
     */
    public static function technicians(string $date): array
    {
        $timezone = currentCompany()->timezone;
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $members = Membership::query()->assignable()->with('user')->get()
            ->filter(fn (Membership $m) => $m->user !== null)
            ->sortBy(fn (Membership $m) => [$m->shows_in_calendar ? 0 : 1, mb_strtolower($m->user->name)])
            ->values();
        $ids = $members->pluck('user_id')->all();

        $visits = JobVisit::query()
            ->where('status', '!=', VisitStatus::Cancelled->value)
            ->whereBetween('scheduled_start', [$day->utc(), $day->endOfDay()->utc()])
            ->whereHas('assignees', fn (Builder $q) => $q->whereIn('users.id', $ids))
            ->with('assignees')
            ->orderBy('scheduled_start')
            ->get();

        return $members->map(fn (Membership $m) => [
            'id' => $m->user->id,
            'name' => $m->user->name,
            'visits' => $visits
                ->filter(fn (JobVisit $v) => $v->assignees->contains('id', $m->user_id))
                ->map(fn (JobVisit $v) => [
                    'id' => $v->id,
                    'job_id' => $v->service_job_id,
                    'start' => $v->scheduled_start->setTimezone($timezone)->format('H:i'),
                    'end' => $v->scheduled_end->setTimezone($timezone)->format('H:i'),
                ])->values()->all(),
        ])->all();
    }

    /**
     * "Technician: …" on the job page and "Not assigned" on My Jobs.
     */
    public function assign(Request $request, ServiceJob $job, AssignJob $assign): RedirectResponse
    {
        Gate::authorize('dispatch', ServiceJob::class);
        Gate::authorize('update', $job);
        $data = $request->validate(['user_id' => ['nullable', 'integer']]);

        $assign->assign($job, isset($data['user_id']) ? (int) $data['user_id'] : null, $request->user());

        return back();
    }

    /**
     * "Transfer" → tick jobs → "Move to…".
     */
    public function transfer(Request $request, AssignJob $assign): RedirectResponse
    {
        Gate::authorize('dispatch', ServiceJob::class);
        $data = $request->validate([
            'visit_ids' => ['required', 'array', 'min:1', 'max:200'],
            'visit_ids.*' => ['integer', 'distinct'],
            'from_user_id' => ['nullable', 'integer'],
            'to_user_id' => ['required', 'integer'],
        ]);

        // Tenant-scoped: another company's visits are simply not found.
        $visits = JobVisit::query()->whereKey($data['visit_ids'])->with('job')->get();
        abort_if($visits->count() !== count($data['visit_ids']), 404);
        foreach ($visits as $visit) {
            Gate::authorize('update', $visit->job);
        }

        $moved = $assign->transfer($visits, isset($data['from_user_id']) ? (int) $data['from_user_id'] : null, (int) $data['to_user_id'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('jobs.transfer.moved', $moved, ['count' => $moved])]);

        return back();
    }

    /**
     * A person's open visits (overdue ones too), for "Transfer" on their Team card.
     */
    public function openVisits(Request $request, Membership $membership): JsonResponse
    {
        Gate::authorize('dispatch', ServiceJob::class);
        $user = $request->user();
        $timezone = currentCompany()->timezone;

        $visits = JobVisit::query()
            ->whereIn('status', VisitStatus::openValues())
            ->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $membership->user_id))
            ->whereHas('job', fn (Builder $q) => $q->visibleTo($user))
            ->with(['job.customer', 'job.property'])
            ->orderBy('scheduled_start')
            ->limit(200)
            ->get();

        return response()->json(['visits' => $visits->map(fn (JobVisit $v) => [
            'id' => $v->id,
            'date' => $v->scheduled_start->setTimezone($timezone)->toDateString(),
            'start' => $v->scheduled_start->setTimezone($timezone)->format('H:i'),
            'end' => $v->scheduled_end->setTimezone($timezone)->format('H:i'),
            'job_number' => $v->job->number,
            'customer' => $v->job->customer?->display_name,
            'address' => $v->job->property?->fullAddress(),
        ])->values()]);
    }
}
