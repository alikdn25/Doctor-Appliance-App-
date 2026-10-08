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
 * address of the job they started before it that day (or their start address, e.g. home) to this job's address.
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

        // The job started before this one today by the same person.
        $previous = JobVisit::query()
            ->whereKeyNot($visit->id)
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $day->startOfDay()->utc())
            ->where('started_at', '<=', $visit->started_at ?? now())
            ->whereIn('status', [VisitStatus::InProgress->value, VisitStatus::Completed->value])
            ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
            ->with('job.property')
            ->latest('started_at')
            ->first();
        $from = $previous?->job?->property?->fullAddress() ?? $user->currentMembership()?->trip_start_address;

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
}
