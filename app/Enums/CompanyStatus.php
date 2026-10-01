<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return __("admin.company_status.{$this->value}");
    }
}
