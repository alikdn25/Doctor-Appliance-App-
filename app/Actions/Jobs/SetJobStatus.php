<?php

namespace App\Actions\Jobs;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual status change by the office (e.g. put on hold, cancel, reopen, correct a mistake).
 * Invoiced and paid are set by invoices and payments, never by hand.
 */
class SetJobStatus
{
    public function __construct(private readonly ChangeJobStatus $changeStatus) {}

    public function handle(ServiceJob $job, JobStatus $to, User $user, ?string $note = null): void
    {
        if ($job->status->isLocked() || ! in_array($to, JobStatus::manual(), true)) {
            throw ValidationException::withMessages(['status' => __('jobs.errors.invalid_transition')]);
        }

        if ($job->status === $to) {
            return;
        }

        DB::transaction(function () use ($job, $to, $user, $note) {
            // A cancelled job keeps no visits on the schedule.
            if ($to === JobStatus::Cancelled) {
                $job->visits()
                    ->where('status', VisitStatus::Scheduled->value)
                    ->update(['status' => VisitStatus::Cancelled->value]);
            }

            $this->changeStatus->handle($job, $to, $user, null, $note);
        });
    }
}
