<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CustomerType: string
{
    use HasOptions;

    case Residential = 'residential';
    case Commercial = 'commercial';
    case PropertyManager = 'property_manager';
    case Strata = 'strata';

    public function label(): string
    {
        return __("customers.types.{$this->value}");
    }
}
