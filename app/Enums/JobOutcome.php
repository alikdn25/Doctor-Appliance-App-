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
    case CustomerDeclined = 'customer_declined';
    case UnableToRepair = 'unable_to_repair';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("jobs.outcomes.{$this->value}");
    }

    public function needsReason(): bool
    {
        return $this !== self::Repaired;
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
        return [self::Repaired, self::CustomerDeclined, self::UnableToRepair];
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
