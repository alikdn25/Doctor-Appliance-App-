<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Follows from the payments: unpaid → partially paid → paid; refunded when payments were all refunded, partially
 * refunded when part was refunded as settled (the customer does not owe it again).
 * Void cancels the invoice.
 */
enum InvoiceStatus: string
{
    use HasOptions;

    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
    // Part of the payment was given back and the rest is settled (e.g. a warranty refund).
    case PartiallyRefunded = 'partially_refunded';
    case Void = 'void';

    public function label(): string
    {
        return __("invoices.statuses.{$this->value}");
    }
}
