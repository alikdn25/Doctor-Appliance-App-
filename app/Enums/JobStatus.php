<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum JobStatus: string
{
    use HasOptions;

    /** A booking saved with "Save as draft": no confirmation went to the customer yet (owner's task, Oct 24). */
    case Draft = 'booking_draft';
    case New = 'new';
    case Scheduled = 'scheduled';
    case OnTheWay = 'on_the_way';
    case InProgress = 'in_progress';
    /** The technician needs a part ("Part needed"); the office has not ordered it yet. */
    case NeedToOrderParts = 'need_to_order_parts';
    /** The part is ordered; the job waits for it to arrive. */
    case WaitingForParts = 'waiting_for_parts';
    /** Every part is on hand; the customer is offered the nearest windows for the repair visit. */
    case PartsReady = 'parts_ready';
    case WaitingForCustomer = 'waiting_for_customer';
    case Completed = 'completed';
    case Invoiced = 'invoiced';
    /** An invoice was sent and money is still owed on it: off Today and the schedule, in the debtors list. */
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return __("jobs.statuses.{$this->value}");
    }

    /**
     * Statuses the office can set by hand. Invoiced, awaiting payment and paid are set by invoices and payments; a draft is finished
     * with "Finish booking", never picked from the list.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status) => ! in_array($status, [self::Draft, self::Invoiced, self::AwaitingPayment, self::Paid], true),
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
        return in_array($this, [self::Invoiced, self::AwaitingPayment, self::Paid], true);
    }

    /**
     * Technicians can work on visits only while the job is active.
     */
    public function allowsVisitWork(): bool
    {
        return ! in_array($this, [self::Cancelled, self::OnHold, self::WaitingForCustomer, self::Invoiced, self::AwaitingPayment, self::Paid], true);
    }

    /**
     * A visit already started can be finished whatever the job's status (paid on site before finishing, too),
     * except on a cancelled job.
     */
    public function allowsFinishingVisit(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * Jobs the parts flow still holds (a diagnosis invoice sent here does not end the job).
     */
    public function waitsForParts(): bool
    {
        return in_array($this, [self::NeedToOrderParts, self::WaitingForParts, self::PartsReady], true);
    }

    /**
     * When a new visit is scheduled the job moves to "scheduled" from these statuses.
     */
    public function reschedulable(): bool
    {
        return in_array($this, [self::New, self::NeedToOrderParts, self::WaitingForParts, self::PartsReady, self::WaitingForCustomer, self::OnHold, self::Completed], true);
    }
}
