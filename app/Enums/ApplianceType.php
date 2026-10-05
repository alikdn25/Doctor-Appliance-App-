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
}
