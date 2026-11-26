<?php

namespace App\Support\Jobs;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\VisitStatus;
use App\Models\Appliance;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Shapes jobs and visits for the frontend. Times are sent as ISO 8601 (UTC);
 * the UI shows them in the company's timezone.
 */
class JobPresenter
{
    /**
     * A row in job lists. Expects customer, property, brand, appliances and visits.assignees loaded.
     *
     * @return array<string, mixed>
     */
    public static function row(ServiceJob $job, ?JobVisit $visit = null): array
    {
        $visit ??= self::currentVisit($job);

        return [
            'id' => $job->id,
            'number' => $job->number,
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'job_type_label' => $job->job_type->label(),
            'visit_type' => $job->visit_type->value,
            'visit_type_label' => $job->visit_type->label(),
            'outcome' => $job->outcome?->value,
            'outcome_label' => $job->outcome?->label(),
            'brand' => $job->brand?->name,
            'customer' => $job->customer?->display_name,
            'address' => $job->property?->fullAddress(),
            'appliances' => $job->appliances->map(fn (Appliance $a) => $a->label())->values(),
            // For the job card of the approved mockup.
            'customer_icon' => $job->customer?->avatarIcon() ?? 'neutral',
            'phone' => $job->customer?->primaryPhone?->number,
            'problem' => $job->description,
            'picture' => self::picture($job),
            'appliance_types' => $job->appliances->map(fn (Appliance $a) => $a->type->label())->values(),
            'visit' => $visit ? [
                'scheduled_start' => self::iso($visit->scheduled_start),
                'scheduled_end' => self::iso($visit->scheduled_end),
                'strict_arrival' => $visit->strict_arrival,
                'assignees' => $visit->assignees->pluck('name')->values(),
            ] : null,
            // Invoiced so far (not void), when the list loaded the invoices for someone who may see them.
            'total' => $job->relationLoaded('invoices') ? self::invoiced($job) : null,
            // The day of a job booked without a time ("No time"); null for a job with no day.
            'planned_date' => $job->planned_date?->toDateString(),
            // Found by a search in the trash: marked Deleted.
            'deleted' => $job->trashed(),
        ];
    }

    /**
     * A search result's extras (owner's task of November 26): the last visit's day and a coloured label — Open,
     * Completed (also invoiced / awaiting payment), Paid or Cancelled.
     *
     * @return array{last_visit: string|null, search_status: string, search_status_label: string}
     */
    public static function searchFields(ServiceJob $job): array
    {
        $last = $job->visits->sortByDesc('scheduled_start')->first();
        $status = match ($job->status) {
            JobStatus::Paid => 'paid',
            JobStatus::Cancelled => 'cancelled',
            JobStatus::Completed, JobStatus::Invoiced, JobStatus::AwaitingPayment => 'completed',
            default => 'open',
        };

        return [
            'last_visit' => $last !== null ? self::iso($last->scheduled_start) : $job->planned_date?->toDateString(),
            'search_status' => $status,
            'search_status_label' => __("jobs.search_status.{$status}"),
        ];
    }

    /**
     * @return array{amount: int, currency: string}|null
     */
    private static function invoiced(ServiceJob $job): ?array
    {
        $invoices = $job->invoices->reject(fn ($invoice) => $invoice->isVoid());

        if ($invoices->isEmpty()) {
            return null;
        }

        $currency = $invoices->first()->currency;

        return ['amount' => (int) $invoices->where('currency', $currency)->sum('total'), 'currency' => $currency];
    }

    /**
     * Picture on a job card: the appliance, several appliances, or tools for an installation.
     */
    public static function picture(ServiceJob $job): ?string
    {
        return match (true) {
            $job->job_type === JobType::Installation => 'installation',
            $job->appliances->count() > 1 => 'multiple',
            default => $job->appliances->first()?->type->value,
        };
    }

    /**
     * The visit to show for a job: the next open one, otherwise the latest.
     */
    public static function currentVisit(ServiceJob $job): ?JobVisit
    {
        $visits = $job->visits;

        return $visits->first(fn (JobVisit $v) => $v->status->isOpen()) ?? $visits->last();
    }

    /**
     * @return array<string, mixed>
     */
    public static function visit(JobVisit $visit, ?User $user, string $timezone): array
    {
        $start = $visit->scheduled_start->setTimezone($timezone);

        return [
            'id' => $visit->id,
            'status' => $visit->status->value,
            'status_label' => $visit->status->label(),
            'scheduled_start' => self::iso($visit->scheduled_start),
            'scheduled_end' => self::iso($visit->scheduled_end),
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $visit->scheduled_end->setTimezone($timezone)->format('H:i'),
            'estimated_duration_minutes' => $visit->estimated_duration_minutes,
            'on_the_way_at' => self::iso($visit->on_the_way_at),
            'started_at' => self::iso($visit->started_at),
            'finished_at' => self::iso($visit->finished_at),
            'minutes_on_job' => $visit->minutesOnJob(),
            'assignees' => $visit->assignees->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'strict_arrival' => $visit->strict_arrival,
            'is_mine' => $user !== null && $visit->assignees->contains('id', $user->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function appliance(Appliance $appliance): array
    {
        return [
            'id' => $appliance->id,
            'type' => $appliance->type->value,
            'type_label' => $appliance->type->label(),
            'manufacturer' => $appliance->manufacturer,
            'model_number' => $appliance->model_number,
            'serial_number' => $appliance->serial_number,
            'under_warranty' => $appliance->isUnderWarranty(),
            'rating_plate_url' => $appliance->rating_plate_url,
            'removed' => $appliance->trashed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function statusChange(JobStatusChange $change): array
    {
        return [
            'id' => $change->id,
            'from_label' => $change->from_status?->label(),
            'to' => $change->to_status->value,
            'to_label' => $change->to_status->label(),
            'user' => $change->user?->name,
            'note' => $change->note,
            'created_at' => self::iso($change->created_at),
        ];
    }

    /**
     * The visit the user should act on next: their visit already under way, else their earliest open one.
     */
    public static function myNextVisit(ServiceJob $job, User $user): ?JobVisit
    {
        $mine = $job->visits->filter(fn (JobVisit $v) => $v->status->isOpen() && $v->assignees->contains('id', $user->id));

        return $mine->first(fn (JobVisit $v) => $v->status !== VisitStatus::Scheduled) ?? $mine->first();
    }

    /**
     * The customer's signature on the job, if taken.
     *
     * @return array{url: string, name: ?string, signed_at: ?string, by: ?string}|null
     */
    public static function signature(ServiceJob $job): ?array
    {
        return $job->signature_path ? [
            'url' => route('jobs.signature.show', $job).'?v='.$job->signed_at?->timestamp,
            'name' => $job->signature_name,
            'signed_at' => self::iso($job->signed_at),
            'by' => $job->signer?->name,
        ] : null;
    }

    /**
     * Not finished yet: texts from its customer are shown as a new-message badge on its card.
     */
    public static function isOpen(ServiceJob $job): bool
    {
        return ! in_array($job->status, [JobStatus::Completed, JobStatus::Invoiced, JobStatus::AwaitingPayment, JobStatus::Paid, JobStatus::Cancelled], true)
            && $job->outcome === null && $job->closed_at === null;
    }

    public static function iso(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }
}
