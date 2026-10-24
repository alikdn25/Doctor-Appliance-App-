<?php

namespace App\Support\Routing;

use App\Models\JobVisit;
use Illuminate\Support\Collection;

/**
 * The order of a person's day (owner's task, October 24): by the arrival window, with the visits put in order on
 * the map ("Apply") in their saved order, in the places such visits take. A visit added later goes by its window.
 */
final class DayOrder
{
    /**
     * @param  Collection<int, JobVisit>  $visits
     * @return Collection<int, JobVisit>
     */
    public static function sort(Collection $visits): Collection
    {
        $byTime = $visits->sortBy(fn (JobVisit $visit) => [$visit->scheduled_start->getTimestamp(), $visit->id])->values();
        $saved = $byTime->filter(fn (JobVisit $visit) => $visit->route_position !== null)
            ->sortBy(fn (JobVisit $visit) => [$visit->route_position, $visit->id])->values();
        $next = 0;

        return $byTime->map(function (JobVisit $visit) use ($saved, &$next) {
            return $visit->route_position !== null ? $saved[$next++] : $visit;
        })->values();
    }
}
