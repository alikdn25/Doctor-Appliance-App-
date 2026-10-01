<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ApplianceType: string
{
    use HasOptions;

    case Washer = 'washer';
    case Dryer = 'dryer';
    case WasherDryerCombo = 'washer_dryer_combo';
    case Refrigerator = 'refrigerator';
    case Freezer = 'freezer';
    case Range = 'range';
    case Oven = 'oven';
    case Cooktop = 'cooktop';
    case Dishwasher = 'dishwasher';
    case Microwave = 'microwave';
    case RangeHood = 'range_hood';
    case Other = 'other';

    public function label(): string
    {
        return __("appliances.types.{$this->value}");
    }
}
