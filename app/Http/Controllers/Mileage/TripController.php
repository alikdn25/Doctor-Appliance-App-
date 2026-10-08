<?php

namespace App\Http\Controllers\Mileage;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mileage log: the month's trips with month and year totals, "+ Trip" for stores, suppliers and other trips,
 * corrections of the trips made from the route, and a CSV log for taxes (date, route, purpose, distance).
 */
class TripController extends Controller
{
    /** Kilometres in one mile. */
    public const MILE = 1.609344;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Trip::class);
        $company = currentCompany();
        $month = $this->month($request);
        $person = $this->person($request);
        $trips = $this->query($request, $person)
            ->whereBetween('trip_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->with(['user', 'job'])
            ->orderByDesc('trip_date')->orderByDesc('id')
            ->get();
        $yearKm = (float) $this->query($request, $person)
            ->whereBetween('trip_date', [$month->startOfYear()->toDateString(), $month->endOfYear()->toDateString()])
            ->sum('distance_km');
        $monthKm = (float) $trips->sum('distance_km');
        $office = $request->user()->hasRole(UserRole::Owner, UserRole::Admin);

        return Inertia::render('trips/index', [
            'month' => $month->format('Y-m'),
            'person' => $person,
            'unit' => $company->distance_unit,
            'rate' => $company->mileage_rate,
            'currency' => $company->currency,
            'trips' => $trips->map(fn (Trip $trip) => [
                'id' => $trip->id,
                'date' => $trip->trip_date->toDateString(),
                'type' => $trip->type,
                'from' => $trip->from_address,
                'to' => $trip->to_address,
                'distance' => $trip->distance_km === null ? null : self::toUnit((float) $trip->distance_km),
                'purpose' => $trip->purpose,
                'driver' => $office ? $trip->user?->name : null,
                'job' => $trip->job ? ['id' => $trip->job->id, 'number' => $trip->job->number] : null,
                'auto' => $trip->job_visit_id !== null,
            ])->values(),
            'totals' => [
                'month' => self::totals($monthKm),
                'year' => self::totals($yearKm),
            ],
            'startAddress' => $request->user()->currentMembership()?->trip_start_address,
            'people' => $office ? Membership::query()->with('user')->get()
                ->filter(fn (Membership $m) => $m->user !== null)
                ->map(fn (Membership $m) => ['value' => (string) $m->user_id, 'label' => $m->user->name])
                ->sortBy('label')->values() : [],
            'types' => array_map(fn (string $type) => ['value' => $type, 'label' => __("trips.types.{$type}")], Trip::TYPES),
            'today' => CarbonImmutable::now($company->timezone)->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Trip::class);

        Trip::create([...$this->validated($request), 'user_id' => $request->user()->id, 'distance_edited' => true]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('trips.saved')]);

        return back();
    }

    public function update(Request $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $data = $this->validated($request);
        $trip->update([...$data, 'distance_edited' => $trip->distance_edited || (string) $trip->distance_km !== (string) $data['distance_km']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('trips.saved')]);

        return back();
    }

    /**
     * Removes the trip from the log; the record is kept (soft delete).
     */
    public function destroy(Trip $trip): RedirectResponse
    {
        Gate::authorize('delete', $trip);

        $trip->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('trips.removed')]);

        return back();
    }

    /**
     * Where the person's working day starts (e.g. home): the first trip of the day is measured from it.
     */
    public function startAddress(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Trip::class);

        $data = $request->validate(['address' => ['nullable', 'string', 'max:255']], [], ['address' => __('trips.start_address')]);
        $request->user()->currentMembership()?->update(['trip_start_address' => $data['address'] ?: null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('trips.start_saved')]);

        return back();
    }

    /**
     * The year's log (or one month's) as CSV for the tax return.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Trip::class);
        $company = currentCompany();
        $month = $this->month($request);
        $wholeYear = $request->query('period') !== 'month';
        [$from, $to] = $wholeYear
            ? [$month->startOfYear(), $month->endOfYear()]
            : [$month->startOfMonth(), $month->endOfMonth()];
        $query = $this->query($request, $this->person($request))->with(['user', 'job'])
            ->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('trip_date')->orderBy('id');
        $unit = $company->distance_unit;
        // User text is exported literally, without executing spreadsheet formulas.
        $text = fn (?string $value) => preg_match('/^[\s]*[=+@\-]/u', $value ?? '') ? "'".$value : ($value ?? '');

        return response()->streamDownload(function () use ($query, $unit, $text) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'From', 'To', 'Purpose', 'Type', "Distance ({$unit})", 'Driver', 'Job']);
            foreach ($query->lazy(500) as $trip) {
                fputcsv($out, [
                    $trip->trip_date->toDateString(), $text($trip->from_address), $text($trip->to_address),
                    $text($trip->purpose), __("trips.types.{$trip->type}"),
                    $trip->distance_km === null ? '' : number_format(self::toUnit((float) $trip->distance_km), 1, '.', ''),
                    $text($trip->user?->name), $trip->job ? '#'.$trip->job->number : '',
                ]);
            }
            fclose($out);
        }, 'mileage-'.($wholeYear ? $from->format('Y') : $from->format('Y-m')).'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Distance and its value at the company rate.
     *
     * @return array{distance: float, amount: int|null}
     */
    public static function totals(float $km): array
    {
        $rate = currentCompany()->mileage_rate;
        $distance = self::toUnit($km);

        return ['distance' => round($distance, 1), 'amount' => $rate === null ? null : (int) round($distance * $rate)];
    }

    public static function toUnit(float $km): float
    {
        return round(currentCompany()->distance_unit === 'mi' ? $km / self::MILE : $km, 1);
    }

    private static function toKm(float $distance): float
    {
        return round(currentCompany()->distance_unit === 'mi' ? $distance * self::MILE : $distance, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'trip_date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(Trip::TYPES)],
            'from_address' => ['nullable', 'string', 'max:255'],
            'to_address' => ['nullable', 'string', 'max:255'],
            'distance' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ], [], [
            'trip_date' => __('trips.fields.date'),
            'distance' => __('trips.fields.distance'),
        ]);

        return [
            'trip_date' => $data['trip_date'],
            'type' => $data['type'],
            'from_address' => $data['from_address'] ?? null,
            'to_address' => $data['to_address'] ?? null,
            'distance_km' => isset($data['distance']) ? self::toKm((float) $data['distance']) : null,
            'purpose' => $data['purpose'] ?? null,
        ];
    }

    /**
     * @return Builder<Trip>
     */
    private function query(Request $request, ?string $person): Builder
    {
        $office = $request->user()->hasRole(UserRole::Owner, UserRole::Admin);

        return Trip::query()
            ->when(! $office, fn (Builder $q) => $q->where('user_id', $request->user()->id))
            ->when($office && $person !== null && $person !== '', fn (Builder $q) => $q->where('user_id', (int) $person));
    }

    private function month(Request $request): CarbonImmutable
    {
        $value = (string) $request->query('month');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m', $value)
            : CarbonImmutable::now(currentCompany()->timezone)->startOfMonth();
    }

    private function person(Request $request): ?string
    {
        $value = $request->query('person');

        return is_string($value) && ctype_digit($value) ? $value : null;
    }
}
