<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\Task;
use App\Support\Maps\Geocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Places the address of a task or a supplier on the map (the app's map service), for "On the way" and the Parts
 * page. Quiet update; without GOOGLE_MAPS_SERVER_KEY nothing is looked up.
 */
class GeocodeAddress implements ShouldQueue
{
    use Queueable;

    /**
     * @param  class-string<Task|Supplier>  $model
     */
    public function __construct(public string $model, public int $id) {}

    public function handle(Geocoder $geocoder): void
    {
        if (! in_array($this->model, [Task::class, Supplier::class], true) || ! Geocoder::enabled()) {
            return;
        }

        $record = $this->model::query()->withoutGlobalScopes()->find($this->id);

        if ($record === null || $record->latitude !== null || blank($record->address)) {
            return;
        }

        $country = Company::query()->whereKey($record->company_id)->value('country') ?? 'US';
        $found = $geocoder->locate((string) $record->address, (string) $country);

        if ($found !== null) {
            $this->model::query()->withoutGlobalScopes()->whereKey($record->id)->whereNull('latitude')
                ->update(['latitude' => $found['lat'], 'longitude' => $found['lng']]);
        }
    }
}
