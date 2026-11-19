<?php

namespace App\Support\Parts;

use App\Actions\Jobs\ChangeJobStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\SuggestPartNumber;
use App\Models\JobPart;
use App\Models\ServiceJob;
use App\Models\User;
use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use Illuminate\Support\Facades\DB;

/**
 * The parts of a job (owner's task of November 19) and the job's status that follows them:
 * - a part to order → Need to order parts;
 * - every part ordered or on hand, not all on hand → Waiting for parts;
 * - every part on hand → Parts ready, and the office is offered the text with the three nearest free windows (sent
 *   only after Confirm on the job).
 * After every change the automatic "Buy at {supplier}" tasks follow too. Must run in a tenant context.
 */
class JobParts
{
    /** Statuses the parts may move the job out of. */
    private const FOLLOWS = [JobStatus::NeedToOrderParts, JobStatus::WaitingForParts, JobStatus::PartsReady];

    /** Statuses a newly needed part moves the job out of (it is not on site and not closed). */
    private const NEEDS_PART_FROM = [JobStatus::New, JobStatus::Scheduled, JobStatus::WaitingForCustomer, JobStatus::OnHold];

    /**
     * @param  array{name: string, part_number?: string|null, quantity?: int|null, supplier_id?: int|null}  $data
     */
    public static function add(ServiceJob $job, array $data, ?User $user): JobPart
    {
        $part = DB::transaction(function () use ($job, $data, $user) {
            $part = new JobPart([
                'service_job_id' => $job->id,
                'name' => trim($data['name']),
                'part_number' => filled($data['part_number'] ?? null) ? trim((string) $data['part_number']) : null,
                'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
                'supplier_id' => $data['supplier_id'] ?? null,
                'status' => JobPart::TO_ORDER,
            ]);
            $part->created_by = $user?->id;
            $part->save();

            self::changed($job, $user);

            return $part;
        });

        if ($part->part_number === null) {
            SuggestPartNumber::dispatch($job->id, (int) $job->company_id)->afterCommit();
        }

        return $part;
    }

    /**
     * Bought (on hand at once), Ordered (waits, with today's date) or Arrived (on hand).
     */
    public static function move(JobPart $part, string $to, ?User $user): ?JobStatus
    {
        return DB::transaction(function () use ($part, $to, $user) {
            $today = now(currentCompany()->timezone)->toDateString();
            $part->status = $to;
            if ($to === JobPart::ORDERED) {
                $part->ordered_on ??= $today;
                $part->received_at = null;
            } elseif ($to === JobPart::ON_HAND) {
                $part->received_at = now();
            } else {
                $part->ordered_on = null;
                $part->received_at = null;
            }
            $part->save();

            return self::changed($part->job, $user);
        });
    }

    /**
     * After the parts of a job changed: the job's status and the supplier tasks. Returns the new status when the job
     * has just become Parts ready.
     */
    public static function changed(ServiceJob $job, ?User $user): ?JobStatus
    {
        $ready = self::syncStatus($job->refresh(), $user);
        SupplierTasks::sync();

        return $ready;
    }

    /**
     * The status the parts call for, or null when the job has no parts.
     */
    public static function target(ServiceJob $job): ?JobStatus
    {
        $statuses = $job->parts()->pluck('status')->all();

        if ($statuses === []) {
            return null;
        }
        if (in_array(JobPart::TO_ORDER, $statuses, true)) {
            return JobStatus::NeedToOrderParts;
        }

        return in_array(JobPart::ORDERED, $statuses, true) ? JobStatus::WaitingForParts : JobStatus::PartsReady;
    }

    /**
     * Moves the job to the status of its parts (rules above). A job on site, closed or cancelled is left as it is,
     * and a job already booked for the repair stays Scheduled when its parts arrive.
     */
    public static function syncStatus(ServiceJob $job, ?User $user): ?JobStatus
    {
        $target = self::target($job);

        if ($target === null || $job->status === $target || $job->outcome !== null) {
            return null;
        }

        $follows = in_array($job->status, self::FOLLOWS, true)
            || (in_array($job->status, self::NEEDS_PART_FROM, true) && $target !== JobStatus::PartsReady);

        if (! $follows) {
            return null;
        }

        app(ChangeJobStatus::class)->handle($job, $target, $user);

        if ($target !== JobStatus::PartsReady) {
            return null;
        }

        self::alertReady($job);

        return JobStatus::PartsReady;
    }

    /**
     * Every part of the job is on hand: the Owners and Admins are offered the text with the nearest windows.
     */
    private static function alertReady(ServiceJob $job): void
    {
        $job->loadMissing('customer');
        $alert = new AppAlert(
            'parts_ready',
            __('parts.ready.title', ['number' => $job->number]),
            __('parts.ready.body', ['name' => $job->customer?->display_name ?? '']),
            route('jobs.show', ['job' => $job->id, 'parts_sms' => 1]),
            mail: false,
        );

        Alerts::send(User::query()->where('is_active', true)->whereHas('memberships', fn ($q) => $q
            ->where('company_id', $job->company_id)->where('is_active', true)
            ->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value]))->get()->all(), $alert);
    }
}
