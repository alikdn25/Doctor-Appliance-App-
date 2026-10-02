<?php

namespace App\Support\Jobs;

use App\Enums\Vertical;

/**
 * Starting services (price book items) for a new company, by vertical. Prices are left empty:
 * each company sets its own in its own currency.
 */
class ServiceDefaults
{
    /**
     * @return list<array{name: string, description: string|null}>
     */
    public static function forVertical(Vertical $vertical): array
    {
        $items = match ($vertical) {
            Vertical::ApplianceRepair => [
                ['Service call / diagnosis', 'Trip charge and diagnosis of one appliance'],
                ['Labour (per hour)', null],
                ['Washer repair', null],
                ['Dryer repair', null],
                ['Refrigerator repair', null],
                ['Dishwasher repair', null],
                ['Range / oven repair', null],
                ['Appliance installation', null],
                ['Dryer vent cleaning', null],
            ],
            Vertical::Handyman => [
                ['Service call', 'Trip charge and assessment'],
                ['Labour (per hour)', null],
                ['TV mounting', null],
                ['Furniture assembly', null],
                ['Shelf / picture hanging', null],
                ['Light fixture replacement', null],
                ['Faucet replacement', null],
                ['Drywall patch', null],
                ['Door adjustment / repair', null],
                ['Caulking (tub / shower)', null],
            ],
        };

        return array_map(fn (array $item) => ['name' => $item[0], 'description' => $item[1]], $items);
    }
}
