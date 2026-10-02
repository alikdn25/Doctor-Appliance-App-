<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why we go out, chosen when the job is created: a first diagnosis, a known problem (straight to the repair), a
 * return visit with parts (follows up an earlier job), or a warranty callback (follows up the original job).
 */
enum VisitType: string
{
    use HasOptions;

    case NewDiagnosis = 'new_diagnosis';
    case KnownProblem = 'known_problem';
    case ReturnVisit = 'return_visit';
    case Callback = 'callback';

    public function label(): string
    {
        return __("jobs.visit_types.{$this->value}");
    }

    /**
     * Follows up an earlier job of the customer (which must be linked).
     */
    public function needsPreviousJob(): bool
    {
        return in_array($this, [self::ReturnVisit, self::Callback], true);
    }
}
