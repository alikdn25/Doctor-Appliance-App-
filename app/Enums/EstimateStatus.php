<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EstimateStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';
    case Declined = 'declined';
    case Invoiced = 'invoiced';

    public function label(): string
    {
        return __("estimates.statuses.{$this->value}");
    }

    /**
     * An estimate can be edited and turned into an invoice until it has been invoiced.
     */
    public function isOpen(): bool
    {
        return $this !== self::Invoiced;
    }
}
