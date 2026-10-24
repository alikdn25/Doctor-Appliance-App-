<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Jobs\JobPresenter;
use App\Support\Routing\DayOrder;
use App\Support\Routing\RoutePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Map tab (owner's task, October 24): every job of a day as numbered pins, by person, the day's route, a better
 * order that keeps the arrival windows ("Optimize", "Apply") and moving a job to another person.
 *
 * A technician sees only their own visits; the Owner and Office see everyone's (within their brands).
 */
class MapController extends Controller
{
    /** Stops a route is planned for at most (Google takes 25 points per route). */
    private const MAX_STOPS = 25;

    public function show(Request $request): Response
    {
        Gate::authorize('viewMap', ServiceJob::class);

        $user = $request->user();
        $company = currentCompany();
        $timezone = $company->timezone;
        $office = $this->office($user);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $day = $this->date($request->query('date'), $timezone) ?? $today;
        $person = $office ? $this->person($request->query('person')) : $user->id;

        $visits = $this->day($user, $day)->get();
        $people = $office ? $this->people($visits) : [['id' => $user->id, 'name' => $user->name]];

        $stops = $this->ordered($visits)
            ->filter(fn (array $group) => $person === 'all' || $group['person'] === $person)
            ->flatMap(fn (array $group) => $group['visits']->values()->map(fn (JobVisit $visit, int $index) => [
                ...$this->stop($visit, $timezone, $user),
                'person_id' => $group['person'],
                'position' => $index + 1,
            ]))
            ->values();

        return Inertia::render('map/index', [
            'date' => $day->format('Y-m-d'),
            'today' => $today->format('Y-m-d'),
            'person' => $person === 'all' ? 'all' : ($person === null ? 'unassigned' : (string) $person),
            'people' => $people,
            'stops' => $stops,
            'canManage' => $office,
            'distanceUnit' => $company->distance_unit ?? 'km',
        ]);
    }

    /**
     * "Apply": the person's visits of the day in the chosen order.
     */
    public function order(Request $request): RedirectResponse
    {
        Gate::authorize('viewMap', ServiceJob::class);
        $data = $request->validate([
            'visit_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_STOPS],
            'visit_ids.*' => ['integer', 'distinct'],
        ]);

        $visits = $this->visits($request->user(), $data['visit_ids']);
        $people = $visits->map(fn (JobVisit $visit) => $visit->assignees->first()?->id)->unique();
        $days = $visits->map(fn (JobVisit $visit) => $visit->scheduled_start->setTimezone(currentCompany()->timezone)->format('Y-m-d'))->unique();
        if ($people->count() > 1 || $days->count() > 1) {
            throw ValidationException::withMessages(['visit_ids' => __('map.one_route')]);
        }

        DB::transaction(function () use ($data, $visits) {
            foreach (array_values($data['visit_ids']) as $index => $id) {
                // Saved quietly: the order is not a change of the visit's time.
                $visits->firstWhere('id', $id)->forceFill(['route_position' => $index + 1])->saveQuietly();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('map.order_saved')]);

        return back();
    }

    /**
     * "Route" and "Optimize": the day in the current order and the best order that keeps the windows. Road times
     * come from the map when it could get them; otherwise they are estimated from the coordinates.
     */
    public function plan(Request $request): JsonResponse
    {
        Gate::authorize('viewMap', ServiceJob::class);
        $data = $request->validate([
            'visit_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_STOPS],
            'visit_ids.*' => ['integer', 'distinct'],
            'minutes' => ['nullable', 'array'],
            'minutes.*' => ['array'],
            'minutes.*.*' => ['numeric', 'min:0', 'max:1440'],
            'km' => ['nullable', 'array'],
            'km.*' => ['array'],
            'km.*.*' => ['numeric', 'min:0', 'max:2000'],
        ]);

        $ids = array_values($data['visit_ids']);
        $timezone = currentCompany()->timezone;
        $visits = $this->visits($request->user(), $ids);
        $ordered = collect($ids)->map(fn (int $id) => $visits->firstWhere('id', $id));
        if ($ordered->contains(fn (JobVisit $visit) => $this->point($visit) === null)) {
            throw ValidationException::withMessages(['visit_ids' => __('map.not_on_map')]);
        }

        $count = count($ids);
        $roads = $this->square($data['minutes'] ?? null, $count) && $this->square($data['km'] ?? null, $count);
        $matrix = $roads
            ? ['minutes' => $data['minutes'], 'km' => $data['km']]
            : RoutePlanner::estimate($ordered->map(fn (JobVisit $visit) => $this->point($visit))->all());

        $stops = $ordered->map(function (JobVisit $visit) use ($timezone) {
            $start = $visit->scheduled_start->setTimezone($timezone);
            $open = $start->hour * 60 + $start->minute;

            return [
                'id' => $visit->id,
                'open' => $open,
                'close' => $open + max(0, (int) round($visit->scheduled_start->diffInMinutes($visit->scheduled_end))),
                'service' => $visit->estimated_duration_minutes ?? 60,
                // On the way or started: it stays where it is.
                'fixed' => $visit->status !== VisitStatus::Scheduled,
            ];
        })->all();

        // Fixed stops go first, in their order.
        usort($stops, fn (array $a, array $b) => ($b['fixed'] <=> $a['fixed']));
        $plan = (new RoutePlanner(
            $stops,
            $this->reorder($matrix['minutes'], $ids, $stops),
            $this->reorder($matrix['km'], $ids, $stops),
        ))->plan();

        return response()->json([
            'estimated' => ! $roads,
            'current' => $plan['current'],
            'best' => $plan['best'],
            'saved_minutes' => max(0, $plan['current']['finish'] - $plan['best']['finish']),
            'saved_drive_minutes' => $plan['current']['drive_minutes'] - $plan['best']['drive_minutes'],
            'saved_km' => round($plan['current']['distance_km'] - $plan['best']['distance_km'], 1),
            'changed' => $plan['best']['order'] !== $plan['current']['order'],
        ]);
    }

    private function office(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    /**
     * The day's visits the person may see: their own for a technician, everyone's for the office.
     *
     * @return Builder<JobVisit>
     */
    private function day(User $user, CarbonImmutable $day): Builder
    {
        return $this->visible($user)
            ->where('status', '!=', VisitStatus::Cancelled->value)
            // A visit belongs to the day it starts on.
            ->where('scheduled_start', '>=', $day->utc())
            ->where('scheduled_start', '<', $day->addDay()->utc())
            ->with(['assignees', 'job.customer.primaryPhone', 'job.property'])
            ->orderBy('scheduled_start')
            ->orderBy('id');
    }

    /**
     * @return Builder<JobVisit>
     */
    private function visible(User $user): Builder
    {
        return JobVisit::query()
            ->whereHas('job', fn (Builder $q) => $q->visibleTo($user))
            ->when(! $this->office($user), fn (Builder $q) => $q->whereHas('assignees', fn (Builder $a) => $a->where('users.id', $user->id)));
    }

    /**
     * Visits named in a request: all of them visible and still open, or the request is refused.
     *
     * @param  list<int>  $ids
     * @return Collection<int, JobVisit>
     */
    private function visits(User $user, array $ids): Collection
    {
        $visits = $this->visible($user)->whereKey($ids)
            ->whereIn('status', VisitStatus::openValues())
            ->with(['assignees', 'job.property'])
            ->get();
        abort_if($visits->count() !== count($ids), 404);

        return $visits;
    }

    /**
     * Visits grouped by person (the first assignee; null = nobody yet), each group in the day's order.
     *
     * @param  Collection<int, JobVisit>  $visits
     * @return Collection<int, array{person: int|null, visits: Collection<int, JobVisit>}>
     */
    private function ordered(Collection $visits): Collection
    {
        return $visits
            ->groupBy(fn (JobVisit $visit) => (string) ($visit->assignees->first()?->id ?? ''))
            ->map(fn (Collection $group, string $key) => [
                'person' => $key === '' ? null : (int) $key,
                'visits' => DayOrder::sort($group),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, JobVisit>  $visits
     * @return list<array{id: int|null, name: string|null}>
     */
    private function people(Collection $visits): array
    {
        $assignable = Membership::query()->assignable()->with('user')->get()
            ->filter(fn (Membership $m) => $m->user !== null)
            ->map(fn (Membership $m) => $m->user);

        return $assignable
            ->concat($visits->flatMap(fn (JobVisit $visit) => $visit->assignees))
            ->unique('id')
            ->sortBy(fn (User $u) => mb_strtolower($u->name))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'assignable' => $assignable->contains('id', $u->id)])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stop(JobVisit $visit, string $timezone, User $user): array
    {
        $job = $visit->job;
        $property = $job->property;

        return [
            ...JobPresenter::visit($visit, $user, $timezone),
            'job_id' => $job->id,
            'number' => $job->number,
            'job_status' => $job->status->value,
            'job_status_label' => $job->status->label(),
            'customer' => $job->customer?->display_name,
            // Call the person at the address (the occupant) when there is one, as on the job page.
            'phone' => $property?->site_contact_phone ?: $job->customer?->primaryPhone?->number,
            'address' => $property?->fullAddress(),
            'coordinates' => $this->point($visit),
            'route_position' => $visit->route_position,
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function point(JobVisit $visit): ?array
    {
        $property = $visit->job->property;

        return $property?->latitude !== null && $property?->longitude !== null
            ? ['lat' => (float) $property->latitude, 'lng' => (float) $property->longitude]
            : null;
    }

    private function person(mixed $value): int|string|null
    {
        return match (true) {
            $value === 'unassigned' => null,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => 'all',
        };
    }

    private function square(mixed $matrix, int $size): bool
    {
        if (! is_array($matrix) || count($matrix) !== $size) {
            return false;
        }

        return collect($matrix)->every(fn ($row) => is_array($row) && count($row) === $size);
    }

    /**
     * The matrix in the planner's stop order (fixed stops moved to the front).
     *
     * @param  list<list<int|float>>  $matrix  in the order of $ids
     * @param  list<int>  $ids
     * @param  list<array{id: int}>  $stops
     * @return list<list<float>>
     */
    private function reorder(array $matrix, array $ids, array $stops): array
    {
        $index = array_flip($ids);

        return array_map(
            fn (array $from) => array_map(fn (array $to) => (float) $matrix[$index[$from['id']]][$index[$to['id']]], $stops),
            $stops,
        );
    }

    private function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        $value = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
