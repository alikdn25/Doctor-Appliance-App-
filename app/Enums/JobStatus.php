<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum JobStatus: string
{
    use HasOptions;

    case New = 'new';
    case Scheduled = 'scheduled';
    case OnTheWay = 'on_the_way';
    case InProgress = 'in_progress';
    case PartsToOrder = 'parts_to_order';
    case WaitingForParts = 'waiting_for_parts';
    case WaitingForCustomer = 'waiting_for_customer';
    case Completed = 'completed';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return __("jobs.statuses.{$this->value}");
    }

    /**
     * Statuses the office can set by hand. Invoiced and paid are set by invoices and payments.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status) => ! in_array($status, [self::Invoiced, self::Paid], true),
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function manualOptions(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::manual());
    }

    /**
     * Whether the job can still be changed by hand and worked on by technicians.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::Invoiced, self::Paid], true);
    }

    /**
     * Technicians can work on visits only while the job is active.
     */
    public function allowsVisitWork(): bool
    {
        return ! in_array($this, [self::Cancelled, self::OnHold, self::WaitingForCustomer, self::Invoiced, self::Paid], true);
    }

    /**
     * When a new visit is scheduled the job moves to "scheduled" from these statuses.
     */
    public function reschedulable(): bool
    {
        return in_array($this, [self::New, self::PartsToOrder, self::WaitingForParts, self::WaitingForCustomer, self::OnHold, self::Completed], true);
    }
}
