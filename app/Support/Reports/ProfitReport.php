<?php

namespace App\Support\Reports;

use App\Enums\JobOutcome;
use App\Enums\LineKind;
use App\Enums\UserRole;
use App\Http\Controllers\Mileage\TripController;
use App\Models\BusinessExpense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Models\Trip;
use App\Models\User;
use App\Support\Billing\JobProfit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Profit of the company for a period (jobs closed in it, company currency):
 * revenue without taxes − cost of parts and materials − card processing fees − business expenses (price plus
 * taxes that cannot be recovered) − mileage at the company rate. A part or material with no cost counts as 0
 * and is listed so the missing cost can be filled in.
 *
 * Purchase costs are private to whoever entered them: the Owner's totals include everyone's (no line is shown),
 * an Admin's profit is hidden when it would reveal another person's cost.
 */
class ProfitReport
{
    /**
     * @param  Collection<int, ServiceJob>  $jobs  Jobs closed in the period
     * @return array<string, mixed>
     */
    public static function for(Collection $jobs, CarbonImmutable $from, CarbonImmutable $to, User $user): array
    {
        $company = currentCompany();
        $allCosts = $user->hasRole(UserRole::Owner);
        $rows = $jobs->filter(fn (ServiceJob $job) => $job->outcome !== JobOutcome::Cancelled)
            ->map(fn (ServiceJob $job) => JobProfit::for($job, $allCosts));
        $hidden = $rows->contains(fn (array $row) => $row['profit'] === null);

        $revenue = (int) $rows->sum('revenue');
        $cost = $hidden ? null : (int) $rows->sum('cost');
        $fees = (int) $rows->sum('fees');
        $expenses = self::expenses($from, $to, $company->currency);
        $km = (float) Trip::query()->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])->sum('distance_km');
        $mileage = TripController::totals($km);
        $profit = $hidden ? null : $revenue - $cost - $fees - $expenses - (int) ($mileage['amount'] ?? 0);

        return [
            'jobs' => $rows->count(),
            'revenue' => $revenue,
            'cost' => $cost,
            'fees' => $fees,
            'expenses' => $expenses,
            'mileage' => $mileage + ['unit' => $company->distance_unit, 'rate_set' => $company->mileage_rate !== null],
            'profit' => $profit,
            'margin' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            'missing_costs' => self::missingCosts($jobs),
        ];
    }

    /**
     * Expenses of the period in the company currency: price plus the taxes that are not recovered.
     */
    private static function expenses(CarbonImmutable $from, CarbonImmutable $to, string $currency): int
    {
        $recoverable = TaxRate::query()->where('is_recoverable', true)->pluck('id')->all();

        return (int) BusinessExpense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->where('currency', $currency)
            ->get()
            ->sum(fn (BusinessExpense $expense) => $expense->amount + ($expense->taxes === null
                // Older entries with one undivided tax amount count it in full.
                ? $expense->tax_amount
                : collect($expense->taxes)->reject(fn (array $tax) => in_array((int) ($tax['tax_rate_id'] ?? 0), $recoverable, true))->sum('amount')));
    }

    /**
     * Parts and materials billed without a purchase cost (counted as 0).
     *
     * @param  Collection<int, ServiceJob>  $jobs
     * @return list<array<string, mixed>>
     */
    private static function missingCosts(Collection $jobs): array
    {
        return InvoiceItem::query()
            ->whereIn('invoice_id', Invoice::query()->whereIn('service_job_id', $jobs->pluck('id'))->where('status', '!=', 'void')->select('id'))
            ->whereIn('kind', [LineKind::Part->value, LineKind::Material->value])
            ->whereNull('unit_cost')
            ->with('invoice.job')
            ->orderBy('invoice_id')
            ->limit(100)
            ->get()
            ->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => strtok($item->description, "\n") ?: $item->description,
                'kind' => $item->kind->value,
                'invoice_id' => $item->invoice_id,
                'invoice' => $item->invoice->number,
                'job_number' => $item->invoice->job?->number,
            ])
            ->values()
            ->all();
    }
}
