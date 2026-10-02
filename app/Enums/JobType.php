<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum JobType: string
{
    use HasOptions;

    case Repair = 'repair';
    case Warranty = 'warranty';
    case Maintenance = 'maintenance';
    case Installation = 'installation';
    case VentCleaning = 'vent_cleaning';
    case Inspection = 'inspection';

    public function label(): string
    {
        return __("jobs.types.{$this->value}");
    }
}
