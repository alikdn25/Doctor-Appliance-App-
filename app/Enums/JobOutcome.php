<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a job ended. Repaired is the normal end; the others need a reason from the company's list (and an optional
 * comment). Cancelled is only for jobs called off before any work was done.
 */
enum JobOutcome: string
{
    use HasOptions;

    case Repaired = 'repaired';
    // Warranty callback: fixed at no charge under the original job's warranty.
    case FixedUnderWarranty = 'fixed_under_warranty';
    case CustomerDeclined = 'customer_declined';
    case UnableToRepair = 'unable_to_repair';
    case Cancelled = 'cancelled';
    // Closed with no invoice or a zero invoice (goodwill, could not diagnose …).
    case NoCharge = 'no_charge';

    public function label(): string
    {
        return __("jobs.outcomes.{$this->value}");
    }

    public function needsReason(): bool
    {
        return ! in_array($this, [self::Repaired, self::FixedUnderWarranty], true);
    }

    /**
     * Outcomes that may be followed by an invoice for the diagnosis / service call only.
     */
    public function allowsDiagnosisInvoice(): bool
    {
        return in_array($this, [self::CustomerDeclined, self::UnableToRepair], true);
    }

    /**
     * Ways to close a job that was worked on (not Cancelled).
     *
     * @return list<self>
     */
    public static function closing(): array
    {
        return [self::Repaired, self::FixedUnderWarranty, self::CustomerDeclined, self::UnableToRepair, self::NoCharge];
    }

    /**
     * Customer declined / unable to repair on a warranty callback may refund the original job.
     */
    public function allowsCallbackRefund(): bool
    {
        return in_array($this, [self::CustomerDeclined, self::UnableToRepair], true);
    }

    /**
     * Reasons offered when the company has not set its own list.
     *
     * @return list<string>
     */
    public function defaultReasons(): array
    {
        $reasons = __("jobs.default_reasons.{$this->value}");

        return is_array($reasons) ? array_values($reasons) : [];
    }
}
