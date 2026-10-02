<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Job types of all verticals; each company offers the ones of its vertical (App\Enums\Vertical::jobTypes()).
 */
enum JobType: string
{
    use HasOptions;

    case Repair = 'repair';
    case Warranty = 'warranty';
    case Maintenance = 'maintenance';
    case Installation = 'installation';
    case VentCleaning = 'vent_cleaning';
    case Inspection = 'inspection';
    // Handyman vertical
    case Assembly = 'assembly';
    case Mounting = 'mounting';

    public function label(): string
    {
        return __("jobs.types.{$this->value}");
    }
}
