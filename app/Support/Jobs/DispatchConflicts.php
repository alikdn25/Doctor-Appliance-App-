<?php

namespace App\Support\Jobs;

use App\Models\JobVisit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Finds visits that overlap for the same person, counting the time on site
 * (the longer of the arrival window and the estimated duration) plus the
 * company's travel buffer before the next visit.
 */
class DispatchConflicts
{
    /**
     * @param  Collection<int, JobVisit>  $visits  With assignees loaded.
     * @return list<int> IDs of visits in conflict.
     */
    public static function find(Collection $visits, int $travelBufferMinutes): array
    {
        $byPerson = [];

        foreach ($visits as $visit) {
            foreach ($visit->assignees as $user) {
                $byPerson[$user->id][] = $visit;
            }
        }

        $conflicts = [];

        foreach ($byPerson as $personVisits) {
            usort($personVisits, fn (JobVisit $a, JobVisit $b) => $a->scheduled_start <=> $b->scheduled_start ?: $a->id <=> $b->id);

            $busyUntil = null;
            $busyVisit = null;

            foreach ($personVisits as $visit) {
                if ($busyUntil !== null && $visit->scheduled_start->lessThan($busyUntil)) {
                    $conflicts[$visit->id] = true;
                    $conflicts[$busyVisit->id] = true;
                }

                $until = self::endOnSite($visit)->addMinutes($travelBufferMinutes);

                if ($busyUntil === null || $until->greaterThan($busyUntil)) {
                    $busyUntil = $until;
                    $busyVisit = $visit;
                }
            }
        }

        return array_map('intval', array_keys($conflicts));
    }

    /**
     * When the person is expected to leave: the end of the arrival window or start + estimated duration.
     */
    public static function endOnSite(JobVisit $visit): CarbonInterface
    {
        $byDuration = $visit->scheduled_start->copy()->addMinutes($visit->estimated_duration_minutes ?? 0);

        return $byDuration->greaterThan($visit->scheduled_end) ? $byDuration : $visit->scheduled_end->copy();
    }
}
