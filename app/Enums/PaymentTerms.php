<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Carbon\CarbonInterface;

/**
 * When an invoice is due (SPEC §7.6): a company default that a customer can override.
 */
enum PaymentTerms: string
{
    use HasOptions;

    case DueOnReceipt = 'due_on_receipt';
    case Net7 = 'net_7';
    case Net15 = 'net_15';
    case Net30 = 'net_30';

    public function label(): string
    {
        return __("invoices.terms.{$this->value}");
    }

    public function days(): int
    {
        return match ($this) {
            self::DueOnReceipt => 0,
            self::Net7 => 7,
            self::Net15 => 15,
            self::Net30 => 30,
        };
    }

    public function dueOn(CarbonInterface $issuedOn): CarbonInterface
    {
        return $issuedOn->copy()->addDays($this->days());
    }
}
