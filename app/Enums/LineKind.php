<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What an estimate/invoice line is: a service (labour, call-out, flat-rate repair), a part or a material.
 */
enum LineKind: string
{
    use HasOptions;

    case Service = 'service';
    case Part = 'part';
    case Material = 'material';

    public function label(): string
    {
        return __("billing.kinds.{$this->value}");
    }
}
