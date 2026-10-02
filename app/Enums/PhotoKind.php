<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PhotoKind: string
{
    use HasOptions;

    case Before = 'before';
    case After = 'after';

    public function label(): string
    {
        return __("jobs.photo_kinds.{$this->value}");
    }
}
