<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum VisitStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case OnTheWay = 'on_the_way';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("jobs.visit_statuses.{$this->value}");
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::OnTheWay, self::InProgress], true);
    }

    /**
     * Visits that are still waiting to happen (counted for the job's "scheduled" status).
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Scheduled->value, self::OnTheWay->value, self::InProgress->value];
    }
}
