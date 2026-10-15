<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Billing\CashLedger;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BusinessExpense;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * More → "Expenses & taxes" (UX-REDESIGN §23), for the Owner: business expenses, mileage and cash on hand in one
 * place, each with this month's figure.
 */
class ExpensesHubController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->hasRole(UserRole::Owner), 403);

        $company = currentCompany();
        $now = CarbonImmutable::now($company->timezone);
        [$from, $to] = [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()];

        $expenses = BusinessExpense::query()
            ->whereBetween('spent_on', [$from, $to])
            ->where('currency', $company->currency)
            ->sum(DB::raw('amount + tax_amount'));
        $kilometres = (float) Trip::query()->whereBetween('trip_date', [$from, $to])->sum('distance_km');
        $cash = collect(CashLedger::balances())->sum(fn (array $byCurrency) => $byCurrency[$company->currency] ?? 0);

        return Inertia::render('expenses/hub', [
            'month' => $now->startOfMonth()->toDateString(),
            'expenses' => (int) $expenses,
            'distance' => round($company->distance_unit === 'mi' ? $kilometres / 1.609344 : $kilometres, 1),
            'distanceUnit' => $company->distance_unit,
            'cash' => (int) $cash,
        ]);
    }
}
