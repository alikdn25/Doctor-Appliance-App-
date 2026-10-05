<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeProperty;
use App\Models\Property;
use App\Support\Maps\Geocoder;
use Illuminate\Console\Command;

/** Looks up map positions of saved addresses that have none (e.g. typed by hand before the key was set). */
class GeocodeProperties extends Command
{
    protected $signature = 'properties:geocode';

    protected $description = 'Add map coordinates to addresses typed by hand (needs GOOGLE_MAPS_SERVER_KEY)';

    public function handle(): int
    {
        if (! Geocoder::enabled()) {
            $this->error('GOOGLE_MAPS_SERVER_KEY is not set.');

            return self::FAILURE;
        }

        $count = 0;
        Property::query()->withoutGlobalScopes()->whereNull('latitude')->select('id')
            ->chunkById(200, function ($properties) use (&$count) {
                foreach ($properties as $property) {
                    GeocodeProperty::dispatch($property->id);
                    $count++;
                }
            });

        $this->info("Queued {$count} addresses.");

        return self::SUCCESS;
    }
}
