<?php

namespace App\Support\Jobs;

use App\Enums\VisitStatus;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\Membership;
use Carbon\CarbonImmutable;

/**
 * The company's arrival windows on a day with the people free in each (no open visit overlapping it). Windows that
 * already started today are left out.
 */
class FreeWindows
{
    /**
     * @return list<array{start: string, end: string, free: list<string>}>
     */
    public static function on(Company $company, string $date): array
    {
        $tz = $company->timezone;
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        $now = CarbonImmutable::now($tz);
        $members = Membership::query()->inCalendar()->with('user')->get()->filter(fn (Membership $m) => $m->user !== null);
        $visits = JobVisit::query()
            ->whereIn('status', VisitStatus::openValues())
            ->whereBetween('scheduled_start', [$day->utc(), $day->endOfDay()->utc()])
            ->with('assignees')
            ->get();

        $windows = [];
        foreach ($company->arrivalWindows() as $window) {
            $start = $day->setTimeFromTimeString($window['start']);
            $end = $day->setTimeFromTimeString($window['end']);

            if ($start->lte($now)) {
                continue;
            }

            $busy = $visits
                ->filter(fn (JobVisit $v) => $v->scheduled_start->lt($end) && $v->scheduled_end->gt($start))
                ->flatMap(fn (JobVisit $v) => $v->assignees->pluck('id'))
                ->unique()
                ->all();

            $free = $members->reject(fn (Membership $m) => in_array($m->user_id, $busy, true))
                ->map(fn (Membership $m) => $m->user->name)->values()->all();

            if ($free !== [] || $members->isEmpty()) {
                $windows[] = ['start' => $window['start'], 'end' => $window['end'], 'free' => $free];
            }
        }

        return $windows;
    }
}
