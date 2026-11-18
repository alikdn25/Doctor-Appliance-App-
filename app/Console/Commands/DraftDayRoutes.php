<?php

namespace App\Console\Commands;

use App\Enums\VisitStatus;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Trip;
use App\Support\Maps\DistanceMatrix;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * At the end of the day (20:00 company time, owner's task of November 18): for each person with a start address
 * (home, Mileage → Start address) and jobs of their own that day, the road distance start → the jobs in the order they
 * were started → start, through the app's map service, saved as a draft in the mileage log. Drafts count in the totals
 * once the Owner confirms or corrects them. No map key or no route: the draft is saved without a distance.
 */
#[Signature('mileage:day-routes')]
#[Description('Draft the day\'s route in the mileage log for each person')]
class DraftDayRoutes extends Command
{
    public const HOUR = 20;

    public function handle(CurrentCompany $tenancy, DistanceMatrix $maps): int
    {
        $count = 0;

        Company::query()->where('status', 'active')->each(function (Company $company) use ($tenancy, $maps, &$count) {
            $now = CarbonImmutable::now($company->timezone);
            if ($now->hour < self::HOUR) {
                return;
            }

            $tenancy->runAs($company, function () use ($now, $maps, &$count) {
                $today = $now->toDateString();
                $members = Membership::query()->with('user')->where('is_active', true)->whereNotNull('trip_start_address')
                    ->where('trip_start_address', '!=', '')
                    ->where(fn ($q) => $q->whereNull('day_route_drafted_on')->orWhere('day_route_drafted_on', '<', $today))->get();

                foreach ($members as $member) {
                    $member->forceFill(['day_route_drafted_on' => $today])->save();
                    $count += $this->draft($member, $now, $maps) ? 1 : 0;
                }
            });
        });

        $this->info("Day routes drafted: {$count}");

        return self::SUCCESS;
    }

    private function draft(Membership $member, CarbonImmutable $now, DistanceMatrix $maps): bool
    {
        $visits = JobVisit::query()
            ->whereHas('assignees', fn ($q) => $q->where('users.id', $member->user_id))
            ->whereNotNull('started_at')
            ->whereBetween('started_at', [$now->startOfDay()->utc(), $now->endOfDay()->utc()])
            ->where('status', '!=', VisitStatus::Cancelled->value)
            ->with('job.property')
            ->orderBy('started_at')
            ->get()
            ->filter(fn (JobVisit $visit) => $visit->job?->property?->hasAddress());

        if ($visits->isEmpty()) {
            return false;
        }

        $start = (string) $member->trip_start_address;
        $stops = [$start, ...$visits->map(fn (JobVisit $v) => $v->job->property->fullAddress())->all(), $start];
        $km = 0.0;
        for ($i = 0; $i < count($stops) - 1; $i++) {
            $leg = $stops[$i] === $stops[$i + 1] ? 0.0 : $maps->kilometres($stops[$i], $stops[$i + 1]);
            if ($leg === null) {
                $km = null;
                break;
            }
            $km += $leg;
        }

        Trip::create([
            'user_id' => $member->user_id,
            'trip_date' => $now->toDateString(),
            'type' => 'client',
            'from_address' => $start,
            'to_address' => $start,
            'distance_km' => $km === null ? null : round($km, 1),
            'purpose' => __('automation.mileage.purpose', ['jobs' => $visits->map(fn (JobVisit $v) => '#'.$v->job->number)->implode(' → ')]),
            'is_draft' => true,
        ]);

        return true;
    }
}
