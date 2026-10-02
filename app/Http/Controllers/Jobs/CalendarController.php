<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Models\Appliance;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Jobs\DispatchConflicts;
use App\Support\Jobs\JobPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dispatch calendar: visits by day or week in per-person lanes, jobs waiting to be scheduled,
 * and each person's route for the day. Times are laid out in the company's timezone.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('dispatch', ServiceJob::class);

        $user = $request->user();
        $company = currentCompany();
        $timezone = $company->timezone;
        $view = $request->query('view') === 'week' ? 'week' : 'day';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $anchor = $this->date($request->query('date'), $timezone) ?? $today;
        $from = $view === 'week' ? $anchor->startOfWeek(CarbonImmutable::MONDAY) : $anchor;
        $to = $from->addDays($view === 'week' ? 7 : 1);

        $visits = JobVisit::query()
            ->where('status', '!=', VisitStatus::Cancelled->value)
            // A visit belongs to the day it starts on.
            ->where('scheduled_start', '>=', $from->utc())
            ->where('scheduled_start', '<', $to->utc())
            ->whereHas('job', fn ($q) => $q->visibleTo($user))
            ->with(['assignees', 'job.customer', 'job.property', 'job.appliances'])
            ->orderBy('scheduled_start')
            ->orderBy('id')
            ->get();

        $conflicts = DispatchConflicts::find($visits, $company->travel_buffer_minutes);
        $brandIds = $user->limitedBrandIds();

        return Inertia::render('calendar/index', [
            'view' => $view,
            'date' => $anchor->format('Y-m-d'),
            'today' => $today->format('Y-m-d'),
            'days' => collect(range(0, $view === 'week' ? 6 : 0))
                ->map(fn (int $i) => $from->addDays($i)->format('Y-m-d'))
                ->all(),
            'previous' => ($view === 'week' ? $anchor->subWeek() : $anchor->subDay())->format('Y-m-d'),
            'next' => ($view === 'week' ? $anchor->addWeek() : $anchor->addDay())->format('Y-m-d'),
            'hours' => $this->hours($company, $from, $to, $visits, $timezone),
            'travelBuffer' => $company->travel_buffer_minutes,
            'lanes' => $this->lanes($visits),
            'visits' => $visits->map(fn (JobVisit $visit) => [
                ...$this->visit($visit, $timezone),
                'conflict' => in_array($visit->id, $conflicts, true),
                'movable' => $visit->status === VisitStatus::Scheduled
                    && $visit->job->status->allowsVisitWork()
                    && ($brandIds === [] || in_array($visit->job->brand_id, $brandIds, true)),
            ])->values(),
            'unscheduled' => $this->unscheduled($user),
            'assignableUsers' => Membership::query()->assignable()->with('user')->get()
                ->filter(fn (Membership $m) => $m->user !== null)
                ->sortBy(fn (Membership $m) => mb_strtolower($m->user->name))
                ->map(fn (Membership $m) => ['id' => $m->user->id, 'name' => $m->user->name, 'role' => $m->role->label()])
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(JobVisit $visit, string $timezone): array
    {
        $job = $visit->job;
        $start = $visit->scheduled_start->setTimezone($timezone);
        $end = $visit->scheduled_end->setTimezone($timezone);
        $dayStart = $start->startOfDay();

        return [
            ...JobPresenter::visit($visit, null, $timezone),
            // Minutes from the start of the visit's local day, for placing it on the grid.
            'start_minutes' => (int) $dayStart->diffInMinutes($start),
            'end_minutes' => (int) min($dayStart->diffInMinutes($end), 24 * 60),
            'on_site_minutes' => (int) $start->diffInMinutes(DispatchConflicts::endOnSite($visit)->setTimezone($timezone)),
            'assignee_ids' => $visit->assignees->pluck('id')->values(),
            'job' => [
                'id' => $job->id,
                'number' => $job->number,
                'status' => $job->status->value,
                'status_label' => $job->status->label(),
                'job_type_label' => $job->job_type->label(),
                'customer' => $job->customer?->display_name,
                'address' => $job->property?->fullAddress(),
                'appliances' => $job->appliances->map(fn (Appliance $a) => $a->label())->values(),
            ],
        ];
    }

    /**
     * One lane per person who can be assigned, plus anyone assigned to a shown visit
     * who no longer can (so no visit disappears), plus "unassigned".
     *
     * @param  Collection<int, JobVisit>  $visits
     * @return list<array{id: int|null, name: string|null, inactive: bool}>
     */
    private function lanes(Collection $visits): array
    {
        $active = Membership::query()->assignable()->with('user')->get()
            ->filter(fn (Membership $m) => $m->user !== null)
            ->map(fn (Membership $m) => $m->user);

        $others = $visits->flatMap(fn (JobVisit $v) => $v->assignees)
            ->unique('id')
            ->reject(fn (User $u) => $active->contains('id', $u->id));

        return $active->sortBy(fn (User $u) => mb_strtolower($u->name))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'inactive' => false])
            ->concat($others->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'inactive' => true]))
            ->push(['id' => null, 'name' => null, 'inactive' => false])
            ->values()
            ->all();
    }

    /**
     * Open jobs with no upcoming visit: new jobs and jobs waiting for parts.
     *
     * @return list<array<string, mixed>>
     */
    private function unscheduled(User $user): array
    {
        return ServiceJob::query()
            ->visibleTo($user)
            ->whereIn('status', [JobStatus::New->value, JobStatus::WaitingForParts->value])
            ->whereDoesntHave('visits', fn ($q) => $q->whereIn('status', VisitStatus::openValues()))
            ->with(['customer', 'property', 'appliances'])
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (ServiceJob $job) => [
                'id' => $job->id,
                'number' => $job->number,
                'status' => $job->status->value,
                'status_label' => $job->status->label(),
                'job_type_label' => $job->job_type->label(),
                'customer' => $job->customer?->display_name,
                'address' => $job->property?->fullAddress(),
                'appliances' => $job->appliances->map(fn (Appliance $a) => $a->label())->values(),
            ])
            ->all();
    }

    /**
     * Visible hours: the company's business hours on the shown days, widened to fit every visit.
     *
     * @param  Collection<int, JobVisit>  $visits
     * @return array{start: int, end: int}
     */
    private function hours(Company $company, CarbonImmutable $from, CarbonImmutable $to, Collection $visits, string $timezone): array
    {
        $open = null;
        $close = null;
        $businessHours = $company->business_hours ?? Company::defaultBusinessHours();

        for ($day = $from; $day->lessThan($to); $day = $day->addDay()) {
            $hours = $businessHours[strtolower($day->format('D'))] ?? null;

            if ($hours === null || ($hours['closed'] ?? true) || ! $hours['open'] || ! $hours['close']) {
                continue;
            }

            $open = min($open ?? PHP_INT_MAX, $this->minutes($hours['open']));
            $close = max($close ?? 0, $this->minutes($hours['close']));
        }

        $start = $open ?? 8 * 60;
        $end = $close ?? 17 * 60;

        foreach ($visits as $visit) {
            $localStart = $visit->scheduled_start->setTimezone($timezone);
            $localEnd = DispatchConflicts::endOnSite($visit)->setTimezone($timezone);
            $start = min($start, $localStart->hour * 60 + $localStart->minute);
            $end = max($end, $localEnd->isSameDay($localStart) ? $localEnd->hour * 60 + $localEnd->minute : 24 * 60);
        }

        return [
            'start' => max(0, intdiv($start, 60)),
            'end' => min(24, (int) ceil($end / 60)),
        ];
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
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
