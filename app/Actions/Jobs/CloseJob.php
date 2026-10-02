<?php

namespace App\Actions\Jobs;

use App\Actions\Billing\SyncJobBillingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Billing\Warranties;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a job with its outcome: repaired, customer declined the repair, or unable to repair (the last two with a
 * reason from the company's list and an optional comment). Cancelling is only for jobs called off before any work
 * was done (customer changed their mind, nobody opened the door). Must run in a tenant context.
 */
class CloseJob
{
    public function __construct(
        private readonly ChangeJobStatus $changeStatus,
        private readonly SyncJobBillingStatus $syncBilling,
    ) {}

    /**
     * Records the outcome of a job whose work is over. $visit is the visit just finished on site, if any.
     */
    public function close(ServiceJob $job, JobOutcome $outcome, ?string $reason, ?string $note, User $user, ?JobVisit $visit = null): void
    {
        if ($outcome === JobOutcome::Cancelled) {
            $this->cancel($job, (string) $reason, $note, $user);

            return;
        }

        $this->validateReason($outcome, $reason);

        DB::transaction(function () use ($job, $outcome, $reason, $note, $user, $visit) {
            $job = ServiceJob::query()->lockForUpdate()->findOrFail($job->id);

            // No charge: nothing billed (no invoice, or invoices totalling zero).
            if ($outcome === JobOutcome::NoCharge
                && $job->invoices()->where('status', '!=', InvoiceStatus::Void->value)->where('total', '>', 0)->exists()) {
                throw ValidationException::withMessages(['outcome' => __('jobs.no_charge.not_allowed')]);
            }

            if ($job->status === JobStatus::Cancelled) {
                throw ValidationException::withMessages(['outcome' => __('jobs.errors.cancelled')]);
            }

            if ($job->visits()->whereIn('status', [VisitStatus::OnTheWay->value, VisitStatus::InProgress->value])->exists()) {
                throw ValidationException::withMessages(['outcome' => __('jobs.errors.visit_in_progress')]);
            }

            // Nothing is left on the schedule of a closed job.
            $job->visits()->where('status', VisitStatus::Scheduled->value)->update(['status' => VisitStatus::Cancelled->value]);

            $this->record($job, $outcome, $reason, $note, $user);
            // Warranties run from the day the job was closed.
            Warranties::stampJob($job);

            if (! $job->status->isLocked() && $job->status !== JobStatus::Completed) {
                $this->changeStatus->handle($job, JobStatus::Completed, $user, $visit, $this->historyNote($outcome, $reason, $note));
            }

            $this->syncBilling->handle($job, $user);
        });
    }

    /**
     * Calls a job off before any work: no visit may have been started.
     */
    public function cancel(ServiceJob $job, string $reason, ?string $note, User $user): void
    {
        $this->validateReason(JobOutcome::Cancelled, $reason);

        DB::transaction(function () use ($job, $reason, $note, $user) {
            $job = ServiceJob::query()->lockForUpdate()->findOrFail($job->id);

            if ($job->status->isLocked() || $job->status === JobStatus::Cancelled) {
                throw ValidationException::withMessages(['status' => __('jobs.errors.invalid_transition')]);
            }

            if ($job->visits()->whereNotNull('started_at')->exists()) {
                throw ValidationException::withMessages(['status' => __('jobs.errors.cancel_after_work')]);
            }

            $job->visits()
                ->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::OnTheWay->value])
                ->update(['status' => VisitStatus::Cancelled->value]);

            $this->record($job, JobOutcome::Cancelled, $reason, $note, $user);
            $this->changeStatus->handle($job, JobStatus::Cancelled, $user, null, $this->historyNote(JobOutcome::Cancelled, $reason, $note));
            $this->syncBilling->handle($job, $user);
        });
    }

    /**
     * A job taken up again (reopened by hand, a new visit booked) has no outcome any more.
     */
    public static function reopen(ServiceJob $job): void
    {
        if ($job->outcome === null) {
            return;
        }

        $job->forceFill(['outcome' => null, 'outcome_reason' => null, 'outcome_note' => null, 'closed_at' => null, 'closed_by' => null])->save();
    }

    private function record(ServiceJob $job, JobOutcome $outcome, ?string $reason, ?string $note, User $user): void
    {
        $job->forceFill([
            'outcome' => $outcome,
            'outcome_reason' => $outcome->needsReason() ? $reason : null,
            'outcome_note' => filled($note) ? trim((string) $note) : null,
            'closed_at' => now(),
            'closed_by' => $user->id,
        ])->save();
    }

    private function validateReason(JobOutcome $outcome, ?string $reason): void
    {
        if ($outcome->needsReason() && ! in_array($reason, currentCompany()->closureReasons($outcome), true)) {
            throw ValidationException::withMessages(['reason' => __('jobs.errors.reason_required')]);
        }
    }

    private function historyNote(JobOutcome $outcome, ?string $reason, ?string $note): string
    {
        return collect([$outcome->label(), $outcome->needsReason() ? $reason : null, $note])->filter()->implode(' — ');
    }
}
