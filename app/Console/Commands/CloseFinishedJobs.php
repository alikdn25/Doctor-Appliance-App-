<?php

namespace App\Console\Commands;

use App\Actions\Billing\SyncJobBillingStatus;
use App\Actions\Jobs\ChangeJobStatus;
use App\Actions\Jobs\CloseJob;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off fix (owner's task of November 25): jobs closed with "Close job" whose status stayed open move to Completed
 * (then Paid / Awaiting payment by their invoices); jobs not closed whose invoices are all paid are closed as repaired.
 * Jobs waiting for parts, the customer or on hold, and jobs with a visit booked ahead or under way today, are left
 * as they are and listed. Run with --dry-run first; back up the database before the real run.
 */
#[Signature('jobs:close-finished {--dry-run : List the jobs without changing anything}')]
#[Description('Move closed or fully paid jobs that still look open to Completed')]
class CloseFinishedJobs extends Command
{
    private const FINISHED = ['completed', 'invoiced', 'awaiting_payment', 'paid', 'cancelled'];

    public function handle(CurrentCompany $tenancy, ChangeJobStatus $changeStatus, SyncJobBillingStatus $sync, CloseJob $close): int
    {
        $dry = (bool) $this->option('dry-run');
        $moved = [];
        $skipped = [];

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $tenancy->runAs($company, function () use ($company, $dry, $changeStatus, $sync, $close, &$moved, &$skipped) {
                $startOfToday = CarbonImmutable::now($company->timezone)->startOfDay()->utc();

                // 1. "Close job" was pressed but the status stayed open.
                $closed = ServiceJob::query()
                    ->where(fn ($q) => $q->whereNotNull('closed_at')->orWhereNotNull('outcome'))
                    ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', JobOutcome::Cancelled->value))
                    ->whereNotIn('status', self::FINISHED)
                    ->orderBy('id')->get();

                foreach ($closed as $job) {
                    $from = $job->status->value;
                    if (! $dry) {
                        DB::transaction(function () use ($job, $changeStatus, $sync) {
                            $changeStatus->handle($job, JobStatus::Completed, null, null, __('jobs.history_entry.closed_fix'));
                            $sync->handle($job, null, autoClose: false);
                        });
                    }
                    $moved[] = [$company->name, $job->number, $from, $dry ? JobStatus::Completed->value : $job->refresh()->status->value, 'closed'];
                }

                // 2. Every invoice paid but the job never closed.
                $paid = ServiceJob::query()
                    ->whereNull('outcome')->whereNull('closed_at')
                    ->whereIn('status', [JobStatus::New->value, JobStatus::Scheduled->value, JobStatus::OnTheWay->value, JobStatus::InProgress->value,
                        JobStatus::Completed->value, JobStatus::Invoiced->value, JobStatus::AwaitingPayment->value])
                    ->whereHas('invoices', fn ($q) => $q->where('status', '!=', InvoiceStatus::Void->value)->where('total', '>', 0))
                    ->orderBy('id')->get();

                foreach ($paid as $job) {
                    $invoices = $job->invoices()->where('status', '!=', InvoiceStatus::Void->value)->get();
                    if (! $invoices->every(fn (Invoice $invoice) => SyncJobBillingStatus::settled($invoice))) {
                        continue;
                    }

                    $busy = $job->visits()->where(fn ($q) => $q
                        ->where(fn ($s) => $s->where('status', VisitStatus::Scheduled->value)->where('scheduled_start', '>', now()))
                        ->orWhere(fn ($o) => $o->whereIn('status', [VisitStatus::OnTheWay->value, VisitStatus::InProgress->value])
                            ->where('scheduled_start', '>=', $startOfToday)))
                        ->exists();
                    if ($busy) {
                        $skipped[] = [$company->name, $job->number, $job->status->value, '-', 'paid, visit ahead or under way today'];

                        continue;
                    }

                    $from = $job->status->value;
                    if (! $dry) {
                        $close->close($job, JobOutcome::Repaired, null, null, null);
                    }
                    $moved[] = [$company->name, $job->number, $from, $dry ? JobStatus::Paid->value : $job->refresh()->status->value, 'paid'];
                }
            });
        }

        $this->info(($dry ? 'Would move' : 'Moved').': '.count($moved));
        if ($moved !== []) {
            $this->table(['Company', 'Job #', 'From', 'To', 'Why'], $moved);
        }
        if ($skipped !== []) {
            $this->warn('Left as they are: '.count($skipped));
            $this->table(['Company', 'Job #', 'Status', '-', 'Why'], $skipped);
        }

        return self::SUCCESS;
    }
}
