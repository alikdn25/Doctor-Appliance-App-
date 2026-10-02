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
    // Replaced by a newer version (kept read-only in the history).
    case Revised = 'revised';

    public function label(): string
    {
        return __("estimates.statuses.{$this->value}");
    }

    /**
     * An estimate can be edited and turned into an invoice until it has been invoiced or replaced by a revision.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Invoiced, self::Revised], true);
    }
}
