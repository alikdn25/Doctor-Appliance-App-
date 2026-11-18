<?php

namespace App\Support\Jobs;

use App\Enums\JobStatus;
use App\Enums\LineKind;
use App\Models\Company;
use App\Models\InvoiceItem;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;

/**
 * "Possible warranty" (owner's task of November 18): the customer had a job finished within the company's warranty
 * window (90 days by default, Settings → Auto messages). Shown on the new job and in the chat with a link to the earlier
 * job: when it was done, what was done, the parts. Only a hint; nothing is changed. Must run in a tenant context.
 */
class PossibleWarranty
{
    /**
     * @return array{job_id: int, number: int, date: string, work: string|null, parts: list<string>}|null
     */
    public static function for(?int $customerId, Company $company, ?int $exceptJobId = null, ?CarbonImmutable $at = null): ?array
    {
        $days = $company->warranty_lookback_days;

        if ($customerId === null || $days <= 0) {
            return null;
        }

        $at ??= CarbonImmutable::now();
        $since = $at->subDays($days);
        $job = ServiceJob::query()->where('customer_id', $customerId)
            ->when($exceptJobId !== null, fn ($q) => $q->whereKeyNot($exceptJobId))
            ->whereIn('status', [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid])
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$since, $at])
            ->latest('completed_at')
            ->first();

        if ($job === null) {
            return null;
        }

        $parts = InvoiceItem::query()
            ->whereIn('invoice_id', $job->invoices()->select('id'))
            ->where('kind', LineKind::Part->value)
            ->pluck('description')->filter()->unique()->values()->all();

        $work = collect([$job->tech_notes, $job->description])->map(fn ($text) => trim((string) $text))->first(fn ($text) => $text !== '');

        return [
            'job_id' => $job->id,
            'number' => $job->number,
            'date' => CarbonImmutable::instance($job->completed_at)->setTimezone($company->timezone)->toDateString(),
            'work' => $work === null ? null : mb_substr($work, 0, 300),
            'parts' => array_slice($parts, 0, 10),
        ];
    }
}
