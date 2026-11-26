<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Support\Jobs\PaidJobCloser;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One-off fix (owner's task of November 26): every open job whose invoices are all paid and that has no visit booked
 * after the last payment is closed (its open visit finished at the time of the last payment). Sends no email or SMS;
 * each job closed is written to the change log. Also run once by a data migration.
 */
#[Signature('jobs:close-paid {--dry-run : List the jobs without changing anything}')]
#[Description('Close open jobs that are paid in full')]
class ClosePaidJobs extends Command
{
    public function handle(CurrentCompany $tenancy, PaidJobCloser $closer): int
    {
        $dry = (bool) $this->option('dry-run');
        $closed = [];
        $left = [];

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $tenancy->runAs($company, function () use ($company, $closer, $dry, &$closed, &$left) {
                $jobs = ServiceJob::query()
                    ->whereNull('outcome')->whereNull('closed_at')
                    ->whereNotIn('status', ['booking_draft', 'cancelled'])
                    ->whereHas('invoices', fn ($q) => $q->where('total', '>', 0)->whereNotIn('status', ['void', 'draft']))
                    ->orderBy('id')->get();

                foreach ($jobs as $job) {
                    $why = $closer->why($job);
                    if ($why !== null) {
                        if ($why !== 'not paid') {
                            $left[] = [$company->name, $job->number, $job->status->value, $why];
                        }

                        continue;
                    }

                    $from = $job->status->value;
                    if (! $dry) {
                        $closer->close($job, $closer->lastPayment($job) ?? now());
                    }
                    $closed[] = [$company->name, $job->number, $from, $dry ? 'paid' : $job->refresh()->status->value];
                }
            });
        }

        $this->info(($dry ? 'Would close' : 'Closed').': '.count($closed));
        if ($closed !== []) {
            $this->table(['Company', 'Job #', 'From', 'To'], $closed);
        }
        if ($left !== []) {
            $this->warn('Paid but left open: '.count($left));
            $this->table(['Company', 'Job #', 'Status', 'Why'], $left);
        }

        return self::SUCCESS;
    }
}
