<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The appliance type of a job, for Statistics (owner's task of November 24). Optional; filled from the job's
 * appliance, by the AI (quick booking, texts, close by voice, rating plate photo) or by hand.
 */
enum ApplianceCategory: string
{
    use HasOptions;

    case Washer = 'washer';
    case Dryer = 'dryer';
    case Dishwasher = 'dishwasher';
    case Refrigerator = 'refrigerator';
    case Freezer = 'freezer';
    case RangeOven = 'range_oven';
    case Cooktop = 'cooktop';
    case WallOven = 'wall_oven';
    case Microwave = 'microwave';
    case RangeHood = 'range_hood';
    case Other = 'other';

    public function label(): string
    {
        return __("statistics.appliances.{$this->value}");
    }

    public static function fromApplianceType(?ApplianceType $type): ?self
    {
        return match ($type) {
            null => null,
            ApplianceType::Refrigerator, ApplianceType::WineCooler => self::Refrigerator,
            ApplianceType::Freezer => self::Freezer,
            ApplianceType::Washer, ApplianceType::WasherDryerCombo => self::Washer,
            ApplianceType::Dryer => self::Dryer,
            ApplianceType::Dishwasher => self::Dishwasher,
            ApplianceType::Range => self::RangeOven,
            ApplianceType::Oven => self::WallOven,
            ApplianceType::Cooktop => self::Cooktop,
            ApplianceType::Microwave => self::Microwave,
            ApplianceType::RangeHood => self::RangeHood,
            ApplianceType::Other => self::Other,
        };
    }
}
