<?php

namespace App\Support\Routing;

/**
 * The day's route of one person (owner's task, October 24): what an order costs and the best order that keeps the
 * arrival windows.
 *
 * Times are minutes from the local midnight of the day. A stop is reached no earlier than its window opens (the
 * technician waits) and should be reached before it closes; minutes past the window count as late. The route starts
 * at the first stop when its window opens (where the technician comes from is not known).
 *
 * Stops at the front marked fixed (already on the way or started) keep their place.
 */
final class RoutePlanner
{
    /** Up to this many movable stops every order is tried; above it the order is improved step by step. */
    private const EXACT_LIMIT = 8;

    /**
     * @param  list<array{id: int, open: int, close: int, service: int, fixed?: bool}>  $stops  in the current order
     * @param  list<list<int|float>>  $minutes  driving minutes from stop i (row) to stop j (column)
     * @param  list<list<int|float>>  $km  road distance in km from stop i to stop j
     */
    public function __construct(
        private readonly array $stops,
        private readonly array $minutes,
        private readonly array $km,
    ) {}

    /**
     * Driving matrices estimated from coordinates, when no road data came from the map: the straight line times a
     * detour factor, at an average city speed.
     *
     * @param  list<array{lat: float, lng: float}>  $points
     * @return array{minutes: list<list<int>>, km: list<list<float>>}
     */
    public static function estimate(array $points): array
    {
        $minutes = [];
        $km = [];
        foreach ($points as $i => $from) {
            foreach ($points as $j => $to) {
                $distance = $i === $j ? 0.0 : self::haversine($from, $to) * 1.35;
                $km[$i][$j] = round($distance, 2);
                $minutes[$i][$j] = $i === $j ? 0 : max(3, (int) round($distance / 40 * 60));
            }
        }

        return ['minutes' => $minutes, 'km' => $km];
    }

    /**
     * What going through the stops in this order means.
     *
     * @param  list<int>  $order  indexes into the stops
     * @return array{order: list<int>, drive_minutes: int, distance_km: float, start: int, finish: int, late_minutes: int, arrivals: array<int, int>}
     */
    public function evaluate(array $order): array
    {
        $drive = 0;
        $distance = 0.0;
        $late = 0;
        $arrivals = [];
        $time = null;
        $previous = null;
        $start = 0;

        foreach ($order as $index) {
            $stop = $this->stops[$index];
            if ($previous === null) {
                $arrive = $stop['open'];
                $start = $arrive;
            } else {
                $leg = (int) round($this->minutes[$previous][$index]);
                $drive += $leg;
                $distance += (float) $this->km[$previous][$index];
                $arrive = max($time + $leg, $stop['open']);
            }
            $late += max(0, $arrive - $stop['close']);
            $arrivals[$stop['id']] = $arrive;
            $time = $arrive + $stop['service'];
            $previous = $index;
        }

        return [
            'order' => array_map(fn (int $index) => $this->stops[$index]['id'], $order),
            'drive_minutes' => $drive,
            'distance_km' => round($distance, 1),
            'start' => $start,
            'finish' => $time ?? 0,
            'late_minutes' => $late,
            'arrivals' => $arrivals,
        ];
    }

    /**
     * The current order (as given) and the best one found.
     *
     * @return array{current: array<string, mixed>, best: array<string, mixed>}
     */
    public function plan(): array
    {
        $current = range(0, count($this->stops) - 1);
        $fixed = [];
        foreach ($this->stops as $index => $stop) {
            if (! ($stop['fixed'] ?? false)) {
                break;
            }
            $fixed[] = $index;
        }
        $movable = array_values(array_diff($current, $fixed));

        $best = count($movable) <= self::EXACT_LIMIT
            ? $this->exact($fixed, $movable)
            : $this->improve($fixed, $movable);

        // Only a real gain changes the order.
        if ($this->cost($best) >= $this->cost($current)) {
            $best = $current;
        }

        return ['current' => $this->evaluate($current), 'best' => $this->evaluate($best)];
    }

    /**
     * Late minutes weigh most, then the time the day ends, then the driving.
     *
     * @param  list<int>  $order
     */
    private function cost(array $order): float
    {
        $result = $this->evaluate($order);

        return $result['late_minutes'] * 1000 + $result['finish'] + $result['drive_minutes'] / 100;
    }

    /**
     * Every order of the movable stops (8! = 40,320 at most).
     *
     * @param  list<int>  $fixed
     * @param  list<int>  $movable
     * @return list<int>
     */
    private function exact(array $fixed, array $movable): array
    {
        $best = [...$fixed, ...$movable];
        $bestCost = $this->cost($best);

        $walk = function (array $prefix, array $rest) use (&$walk, &$best, &$bestCost): void {
            if ($rest === []) {
                $cost = $this->cost($prefix);
                if ($cost < $bestCost) {
                    $best = $prefix;
                    $bestCost = $cost;
                }

                return;
            }
            foreach ($rest as $key => $index) {
                $next = $rest;
                unset($next[$key]);
                $walk([...$prefix, $index], array_values($next));
            }
        };
        $walk($fixed, $movable);

        return $best;
    }

    /**
     * Longer days: move one stop elsewhere or reverse a stretch while that makes the day better.
     *
     * @param  list<int>  $fixed
     * @param  list<int>  $movable
     * @return list<int>
     */
    private function improve(array $fixed, array $movable): array
    {
        $order = $movable;
        $count = count($order);
        $cost = $this->cost([...$fixed, ...$order]);

        for ($round = 0; $round < 50; $round++) {
            $better = false;
            for ($i = 0; $i < $count; $i++) {
                for ($j = 0; $j < $count; $j++) {
                    if ($i === $j) {
                        continue;
                    }
                    $moved = $order;
                    [$stop] = array_splice($moved, $i, 1);
                    array_splice($moved, $j, 0, [$stop]);
                    $reversed = $order;
                    if ($i < $j) {
                        array_splice($reversed, $i, $j - $i + 1, array_reverse(array_slice($order, $i, $j - $i + 1)));
                    }
                    foreach ([$moved, $reversed] as $candidate) {
                        $candidateCost = $this->cost([...$fixed, ...$candidate]);
                        if ($candidateCost < $cost - 0.001) {
                            $order = $candidate;
                            $cost = $candidateCost;
                            $better = true;
                        }
                    }
                }
            }
            if (! $better) {
                break;
            }
        }

        return [...$fixed, ...$order];
    }

    /**
     * @param  array{lat: float, lng: float}  $from
     * @param  array{lat: float, lng: float}  $to
     */
    private static function haversine(array $from, array $to): float
    {
        $lat = deg2rad($to['lat'] - $from['lat']);
        $lng = deg2rad($to['lng'] - $from['lng']);
        $a = sin($lat / 2) ** 2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat'])) * sin($lng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
