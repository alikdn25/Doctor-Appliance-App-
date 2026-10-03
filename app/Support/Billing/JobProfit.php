<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobCostItem;
use App\Models\Payment;
use App\Models\ServiceJob;

/**
 * Profit of a job, in minor units of the company currency:
 * revenue without taxes (non-void invoices, less refunds given as settled)
 * − cost of every line (billed and internal) and of the job's cost lines (parts/materials on no invoice;
 *   supplier tax that is not recoverable included)
 * − processor fees (e.g. Square) on its payments.
 */
class JobProfit
{
    /**
     * @return array{revenue: int, cost: int, fees: int, profit: int, margin: float|null}
     */
    public static function for(ServiceJob $job): array
    {
        $currency = currentCompany()->currency;
        $invoices = Invoice::query()->where('service_job_id', $job->id)->where('currency', $currency)
            ->where('status', '!=', InvoiceStatus::Void->value)->with('items')->get();

        $revenue = 0;
        $cost = 0;

        foreach ($invoices as $invoice) {
            $net = $invoice->total - $invoice->tax_total;
            // Refunds given as settled lower the revenue in proportion.
            $revenue += $invoice->total > 0 ? (int) round($net * max(0, $invoice->total - $invoice->credited_amount) / $invoice->total) : $net;
            $cost += $invoice->items->sum(fn (InvoiceItem $item) => $item->totalCost());
        }

        $cost += JobCostItem::query()->where('service_job_id', $job->id)->where('currency', $currency)
            ->get()->sum(fn (JobCostItem $item) => $item->totalCost());
        $fees = (int) Payment::query()->valid()->whereIn('invoice_id', $invoices->pluck('id'))->sum('processing_fee');
        $profit = $revenue - $cost - $fees;
        $private = $invoices->contains(fn (Invoice $invoice) => $invoice->items->contains(fn (InvoiceItem $item) => $item->unit_cost !== null && ! CostAccess::owns(auth()->user(), $item)));

        return [
            'revenue' => $revenue,
            'cost' => $private ? null : $cost,
            'fees' => $fees,
            'profit' => $private ? null : $profit,
            'margin' => ! $private && $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
        ];
    }
}
