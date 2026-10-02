<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Follows from the payments: unpaid → partially paid → paid; refunded when online payments were all refunded.
 * Void cancels the invoice.
 */
enum InvoiceStatus: string
{
    use HasOptions;

    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Void = 'void';

    public function label(): string
    {
        return __("invoices.statuses.{$this->value}");
    }
}
