<?php

namespace App\Jobs;

use App\Models\Property;
use App\Support\Maps\Geocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Adds map coordinates to an address that was typed by hand (no Google Places pick). */
class GeocodeProperty implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $propertyId) {}

    public function handle(Geocoder $geocoder): void
    {
        $property = Property::query()->withoutGlobalScopes()->find($this->propertyId);

        if ($property === null || $property->latitude !== null) {
            return;
        }

        $found = $geocoder->locate($property->fullAddress(), $property->country);

        if ($found === null) {
            return;
        }

        // Quiet update: no saving events, the address itself is unchanged.
        Property::query()->withoutGlobalScopes()->whereKey($property->id)->whereNull('latitude')->update([
            'latitude' => $found['lat'],
            'longitude' => $found['lng'],
        ]);
    }
}
