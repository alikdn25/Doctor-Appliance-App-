<?php

namespace App\Actions\Mileage;

use App\Enums\VisitStatus;
use App\Jobs\MeasureTrip;
use App\Models\JobVisit;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * When a technician starts a job, the trip that brought them there is added to their mileage log: from the
 * address of the job they started before it that day, or the stop they drove to since (a parts store), or their
 * start address (e.g. home), to this job's address.
 * The distance comes from the map service (in the background) and can be corrected by hand. One trip per visit.
 */
class RecordRouteTrip
{
    public function handle(JobVisit $visit, User $user): ?Trip
    {
        if (Trip::query()->withTrashed()->where('job_visit_id', $visit->id)->exists()) {
            return null;
        }

        $company = currentCompany();
        $job = $visit->job()->with('property', 'customer')->firstOrFail();
        $to = $job->property?->fullAddress();
        $day = CarbonImmutable::now($company->timezone);

        $from = self::lastPoint($user, $visit);

        $trip = Trip::create([
            'user_id' => $user->id,
            'trip_date' => $day->toDateString(),
            'type' => 'client',
            'from_address' => $from,
            'to_address' => $to,
            'purpose' => __('trips.client_purpose', ['number' => $job->number, 'customer' => $job->customer?->display_name ?? '']),
            'service_job_id' => $job->id,
            'job_visit_id' => $visit->id,
        ]);

        // The road distance is looked up in the background so Start stays instant on a weak signal.
        if ($from && $to) {
            MeasureTrip::dispatch($trip->id, $company->id)->afterCommit();
        }

        return $trip;
    }

    /**
     * Where the person was last today: the stop of their latest trip (e.g. a parts store) or the job they started
     * last, whichever came later; otherwise their start address (e.g. home).
     */
    public static function lastPoint(User $user, ?JobVisit $except = null): ?string
    {
        $dayStart = CarbonImmutable::now(currentCompany()->timezone)->startOfDay()->utc();
        $previous = JobVisit::query()
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $dayStart)
            ->whereIn('status', [VisitStatus::InProgress->value, VisitStatus::Completed->value])
            ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
            ->with('job.property')
            ->latest('started_at')
            ->first();
        $stop = Trip::query()
            ->where('user_id', $user->id)
            ->whereNull('job_visit_id')
            ->whereNotNull('to_address')
            ->where('created_at', '>=', $dayStart)
            ->latest('created_at')
            ->first();

        if ($stop !== null && ($previous === null || $stop->created_at->greaterThan($previous->started_at))) {
            return $stop->to_address;
        }

        return $previous?->job?->property?->fullAddress() ?? $user->currentMembership()?->trip_start_address;
    }
}
