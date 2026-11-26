<?php

namespace App\Actions\Jobs;

use App\Enums\JobStatus;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Reopen job" (owner's task of November 26): a completed, invoiced, awaiting-payment, paid or cancelled job is taken
 * up again with a new visit on the same job (or a day without a time, or no date). Notes, photos, invoices and payments
 * stay. The job is open again (Scheduled with a visit, else New) and a paid invoice does not close it again
 * (SyncJobBillingStatus) until it is completed or closed by hand. Logged in the change log and the status history.
 */
class ReopenJob
{
    /** Statuses that show "Reopen job". */
    public const CLOSED = [JobStatus::Completed, JobStatus::Invoiced, JobStatus::AwaitingPayment, JobStatus::Paid, JobStatus::Cancelled];

    public function __construct(
        private readonly ChangeJobStatus $changeStatus,
        private readonly SaveVisit $saveVisit,
        private readonly AuditLogger $audit,
    ) {}

    public static function canReopen(ServiceJob $job): bool
    {
        return in_array($job->status, self::CLOSED, true);
    }

    /**
     * @param  array{attributes: array<string, mixed>, assignee_ids: list<int>}|null  $visit  null: no time
     * @param  string|null  $day  The day of a booking without a time (No time); null with no visit: no date
     */
    public function handle(ServiceJob $job, ?array $visit, ?string $day, User $user): void
    {
        if (! self::canReopen($job)) {
            throw ValidationException::withMessages(['status' => __('jobs.reopen.not_closed')]);
        }

        DB::transaction(function () use ($job, $visit, $day, $user) {
            $from = $job->status;
            CloseJob::reopen($job);
            $job->forceFill(['reopened_at' => now(), 'planned_date' => $visit === null ? $day : null])->save();
            $this->changeStatus->handle($job, JobStatus::New, $user, null, __('jobs.reopen.history'));

            if ($visit !== null) {
                // New → Scheduled with the visit.
                $this->saveVisit->handle($job, null, $visit['attributes'], $visit['assignee_ids'], $user);
            }

            $this->audit->record('job.reopened', $job, ['number' => $job->number, 'from' => $from->value]);
        });
    }
}
