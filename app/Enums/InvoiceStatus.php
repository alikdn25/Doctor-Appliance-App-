<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Follows from the payments: unpaid → partially paid → paid. Void cancels the invoice.
 */
enum InvoiceStatus: string
{
    use HasOptions;

    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return __("invoices.statuses.{$this->value}");
    }
}
