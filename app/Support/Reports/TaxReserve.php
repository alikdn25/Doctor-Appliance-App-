<?php

namespace App\Support\Reports;

use App\Models\BusinessExpense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Models\TaxReserveTransfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How much to move to the tax account for a month (UX-REDESIGN §17), in the company currency:
 *
 * - each sales tax the company charged: on invoices paid in the month (each payment carries its share of the
 *   invoice's taxes; refunds take theirs back, void payments do not count) or on invoices issued in the month,
 *   as set by the Owner; optionally minus the recoverable tax of the same name paid on business expenses;
 * - income and dividend tax: the Owner's rate × the month's net profit as in Reports (0 when it is a loss);
 * - total = the taxes + the income tax, never below 0.
 *
 * The transfer is made by the Owner in the bank and marked here. Must run in a tenant context.
 */
class TaxReserve
{
    /**
     * @return array{month: string, currency: string, basis: string, rate: string, taxes: list<array{name: string, collected: int, deducted: int, amount: int}>, profit: int, income_tax: int, total: int, transferred: int, left: int}
     */
    public static function month(CarbonImmutable $month, User $owner): array
    {
        $company = currentCompany();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $collected = $company->tax_reserve_basis === 'issued'
            ? self::issued($from, $to, $company->currency)
            : self::paid($from, $to, $company->currency);
        $deducted = $company->tax_reserve_deduct_expense_tax ? self::expenseTax($from, $to, $company->currency) : [];

        $taxes = collect(array_unique([...array_keys($collected), ...array_keys($deducted)]))
            ->sort()
            ->map(fn (string $name) => [
                'name' => $name,
                'collected' => $collected[$name] ?? 0,
                'deducted' => $deducted[$name] ?? 0,
                'amount' => ($collected[$name] ?? 0) - ($deducted[$name] ?? 0),
            ])
            ->values()
            ->all();

        $profit = (int) (ProfitReport::for(self::closedJobs($from, $to, $owner), $from, $to, $owner)['profit'] ?? 0);
        $incomeTax = (int) round(max(0, $profit) * (float) $company->tax_reserve_rate / 100);
        $total = max(0, array_sum(array_column($taxes, 'amount')) + $incomeTax);
        $transferred = (int) TaxReserveTransfer::query()->whereDate('month', $from->toDateString())
            ->where('currency', $company->currency)->sum('amount');

        return [
            'month' => $from->toDateString(),
            'currency' => $company->currency,
            'basis' => $company->tax_reserve_basis,
            'rate' => (string) $company->tax_reserve_rate,
            'taxes' => $taxes,
            'profit' => $profit,
            'income_tax' => $incomeTax,
            'total' => $total,
            'transferred' => $transferred,
            'left' => max(0, $total - $transferred),
        ];
    }

    /**
     * Sales tax on the money received in the month: each payment's share of its invoice's taxes.
     *
     * @return array<string, int> Tax name => minor units
     */
    private static function paid(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        $tz = currentCompany()->timezone;
        $sums = [];

        Payment::query()->valid()
            ->whereNotNull('invoice_id')
            ->where('currency', $currency)
            ->whereBetween('received_at', [$from->startOfDay()->shiftTimezone($tz)->utc(), $to->endOfDay()->shiftTimezone($tz)->utc()])
            ->with('invoice')
            ->get()
            ->each(function (Payment $payment) use (&$sums) {
                $invoice = $payment->invoice;

                if ($invoice === null || $invoice->total <= 0) {
                    return;
                }

                $share = $payment->amount / $invoice->total;

                foreach ($invoice->taxes ?? [] as $tax) {
                    $sums[$tax['name']] = ($sums[$tax['name']] ?? 0) + (int) round((int) $tax['amount'] * $share);
                }
            });

        return $sums;
    }

    /**
     * Sales tax on the invoices issued in the month (less what was refunded as settled).
     *
     * @return array<string, int>
     */
    private static function issued(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        $sums = [];

        Invoice::query()
            ->where('status', '!=', 'void')
            ->where('currency', $currency)
            ->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->each(function (Invoice $invoice) use (&$sums) {
                if ($invoice->total <= 0) {
                    return;
                }

                $share = ($invoice->total - $invoice->credited_amount) / $invoice->total;

                foreach ($invoice->taxes ?? [] as $tax) {
                    $sums[$tax['name']] = ($sums[$tax['name']] ?? 0) + (int) round((int) $tax['amount'] * $share);
                }
            });

        return $sums;
    }

    /**
     * Recoverable tax paid on the month's business expenses, by tax name.
     *
     * @return array<string, int>
     */
    private static function expenseTax(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        $recoverable = TaxRate::query()->where('is_recoverable', true)->pluck('name', 'id');
        $sums = [];

        BusinessExpense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->where('currency', $currency)
            ->get()
            ->each(function (BusinessExpense $expense) use ($recoverable, &$sums) {
                foreach ($expense->taxes ?? [] as $tax) {
                    $name = $recoverable[(int) ($tax['tax_rate_id'] ?? 0)] ?? null;

                    if ($name !== null) {
                        $sums[$name] = ($sums[$name] ?? 0) + (int) $tax['amount'];
                    }
                }
            });

        return $sums;
    }

    /**
     * @return Collection<int, ServiceJob>
     */
    private static function closedJobs(CarbonImmutable $from, CarbonImmutable $to, User $owner)
    {
        $tz = currentCompany()->timezone;

        return ServiceJob::query()
            ->visibleTo($owner)
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from->startOfDay()->shiftTimezone($tz)->utc(), $to->endOfDay()->shiftTimezone($tz)->utc()])
            ->with(['appliances', 'brand', 'visits.assignees'])
            ->get();
    }
}
