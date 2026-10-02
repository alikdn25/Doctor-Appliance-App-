<?php

namespace App\Support\Jobs;

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
    public static function row(ServiceJob $job): array
    {
        $visit = self::currentVisit($job);

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
            'visit' => $visit ? [
                'scheduled_start' => self::iso($visit->scheduled_start),
                'scheduled_end' => self::iso($visit->scheduled_end),
                'strict_arrival' => $visit->strict_arrival,
                'assignees' => $visit->assignees->pluck('name')->values(),
            ] : null,
        ];
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

    public static function iso(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }
}
