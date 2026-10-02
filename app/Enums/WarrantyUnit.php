<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum WarrantyUnit: string
{
    use HasOptions;

    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';

    public function label(): string
    {
        return __("billing.warranty_units.{$this->value}");
    }
}
