<?php

namespace App\Support\Reports;

use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Invoice-date revenue and current estimate-version conversion, with currencies kept separate. */
class BusinessReport
{
    public static function for(CarbonImmutable $from, CarbonImmutable $to, User $user): array
    {
        $visibleJobs = fn ($query) => $query->withTrashed()->visibleTo($user);
        $invoices = Invoice::query()
            ->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->whereHas('job', $visibleJobs)
            ->with(['brand', 'job.visits.assignees'])
            ->get();

        $rows = $invoices->map(function (Invoice $invoice) {
            $job = $invoice->job;
            $visit = $job->visits->filter(fn (JobVisit $v) => $v->started_at !== null)->last() ?? $job->visits->last();
            $technician = $visit?->assignees->first();
            $net = $invoice->total - $invoice->tax_total;
            // A settled refund reduces net revenue proportionally; taxes and tips are not revenue.
            $revenue = $invoice->total > 0
                ? (int) round($net * max(0, $invoice->total - $invoice->credited_amount) / $invoice->total)
                : $net;

            return [
                'currency' => $invoice->currency,
                'revenue' => $revenue,
                'brand_id' => $invoice->brand_id,
                'brand' => $invoice->brand?->name ?? __('reports.unassigned'),
                'technician_id' => $technician?->id ?? 0,
                'technician' => $technician?->name ?? __('reports.unassigned'),
                'jobType_id' => $job->job_type->value,
                'jobType' => $job->job_type->label(),
                'source_id' => $job->lead_source?->value ?? '',
                'source' => $job->lead_source?->label() ?? __('reports.no_source'),
            ];
        });

        $estimates = Estimate::query()
            ->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->whereNull('revised_at')
            ->where('status', '!=', EstimateStatus::Revised->value)
            ->whereHas('job', $visibleJobs)
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhereIn('status', [
                EstimateStatus::Approved->value, EstimateStatus::Invoiced->value, EstimateStatus::Declined->value,
            ]))
            ->get(['id', 'status']);
        $approved = $estimates->filter(fn (Estimate $estimate) => in_array($estimate->status, [EstimateStatus::Approved, EstimateStatus::Invoiced], true))->count();

        return [
            'totals' => $rows->groupBy('currency')->map(fn (Collection $group, string $currency) => [
                'currency' => $currency, ...self::sum($group),
            ])->sortBy('currency')->values()->all(),
            'byBrand' => self::group($rows, 'brand'),
            'byTechnician' => self::group($rows, 'technician'),
            'byJobType' => self::group($rows, 'jobType'),
            'bySource' => self::group($rows, 'source'),
            'conversion' => [
                'estimates' => $estimates->count(),
                'approved' => $approved,
                'rate' => $estimates->isEmpty() ? null : round($approved / $estimates->count() * 100, 1),
            ],
        ];
    }

    private static function sum(Collection $rows): array
    {
        $revenue = (int) $rows->sum('revenue');

        return [
            'invoices' => $rows->count(),
            'revenue' => $revenue,
            'average' => $rows->isEmpty() ? null : (int) round($revenue / $rows->count()),
        ];
    }

    private static function group(Collection $rows, string $dimension): array
    {
        return $rows->groupBy(fn (array $row) => $row[$dimension.'_id'].'|'.$row['currency'])
            ->map(fn (Collection $group, string $key) => [
                'key' => $key,
                'name' => $group->first()[$dimension],
                'currency' => $group->first()['currency'],
                ...self::sum($group),
            ])->sortByDesc('revenue')->values()->all();
    }
}
