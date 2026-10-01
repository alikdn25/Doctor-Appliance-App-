<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PhoneLabel: string
{
    use HasOptions;

    case Mobile = 'mobile';
    case Home = 'home';
    case Work = 'work';
    case Other = 'other';

    public function label(): string
    {
        return __("customers.phone_labels.{$this->value}");
    }
}
