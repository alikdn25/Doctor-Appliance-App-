<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EmailLabel: string
{
    use HasOptions;

    case Personal = 'personal';
    case Work = 'work';
    case Billing = 'billing';
    case Other = 'other';

    public function label(): string
    {
        return __("customers.email_labels.{$this->value}");
    }
}
