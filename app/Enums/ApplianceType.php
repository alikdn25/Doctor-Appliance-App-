<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ApplianceType: string
{
    use HasOptions;

    // Order of the picker tiles (docs/DESIGN.md, Select appliance).
    case Refrigerator = 'refrigerator';
    case Freezer = 'freezer';
    case WineCooler = 'wine_cooler';
    case Washer = 'washer';
    case Dryer = 'dryer';
    case WasherDryerCombo = 'washer_dryer_combo';
    case Dishwasher = 'dishwasher';
    case Range = 'range';
    case Oven = 'oven';
    case Cooktop = 'cooktop';
    case Microwave = 'microwave';
    case RangeHood = 'range_hood';
    case Other = 'other';

    public function label(): string
    {
        return __("appliances.types.{$this->value}");
    }

    /**
     * Types whose label contains the search term ("wash" → washer, washer/dryer combo, dishwasher).
     *
     * @return list<string>
     */
    public static function matching(string $term): array
    {
        $term = mb_strtolower(trim($term));

        if (mb_strlen($term) < 3) {
            return [];
        }

        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => str_contains(mb_strtolower($type->label()), $term)),
        ));
    }
}
