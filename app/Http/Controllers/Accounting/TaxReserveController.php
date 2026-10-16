<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TaxReserveTransfer;
use App\Services\AuditLogger;
use App\Support\Locale\Currencies;
use App\Support\Reports\TaxReserve;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses & taxes → Tax reserve (UX-REDESIGN §17), Owner only: what to move to the tax account for a month, the
 * transfers marked as done, the months still short, the settings and a CSV for the bookkeeper.
 */
class TaxReserveController extends Controller
{
    /** Earlier months checked for money not moved yet. */
    private const LOOK_BACK = 6;

    public function index(Request $request): Response
    {
        $this->owner($request);
        $company = currentCompany();
        $now = CarbonImmutable::now($company->timezone);
        // By default the month just finished: its money is moved after it ends.
        $month = $this->month($request) ?? $now->subMonthNoOverflow()->startOfMonth();

        $behind = [];
        if ($company->tax_reserve_enabled) {
            for ($i = 1; $i <= self::LOOK_BACK; $i++) {
                $earlier = $month->subMonthsNoOverflow($i);
                $reserve = TaxReserve::month($earlier, $request->user());

                if ($reserve['left'] > 0) {
                    $behind[] = ['month' => $reserve['month'], 'left' => $reserve['left']];
                }
            }
        }

        return Inertia::render('expenses/tax-reserve', [
            'settings' => [
                'enabled' => $company->tax_reserve_enabled,
                'rate' => (string) (float) $company->tax_reserve_rate,
                'deduct_expense_tax' => $company->tax_reserve_deduct_expense_tax,
                'basis' => $company->tax_reserve_basis,
                'reminder_day' => $company->tax_reserve_reminder_day,
            ],
            'reserve' => $company->tax_reserve_enabled ? TaxReserve::month($month, $request->user()) : null,
            'behind' => $behind,
            'month' => $month->format('Y-m'),
            'currentMonth' => $now->format('Y-m'),
            'today' => $now->toDateString(),
            'transfers' => TaxReserveTransfer::query()->with('creator')->orderByDesc('month')->orderByDesc('transferred_on')->limit(60)->get()
                ->map(fn (TaxReserveTransfer $transfer) => [
                    'id' => $transfer->id,
                    'month' => $transfer->month->toDateString(),
                    'amount' => $transfer->amount,
                    'currency' => $transfer->currency,
                    'transferred_on' => $transfer->transferred_on->toDateString(),
                    'note' => $transfer->note,
                    'by' => $transfer->creator?->name,
                ])->values(),
        ]);
    }

    public function settings(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->owner($request);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'deduct_expense_tax' => ['required', 'boolean'],
            'basis' => ['required', Rule::in(['paid', 'issued'])],
            'reminder_day' => ['nullable', 'integer', 'min:1', 'max:28'],
        ], [], [
            'rate' => __('tax_reserve.settings.rate'),
            'reminder_day' => __('tax_reserve.settings.reminder_day'),
        ]);

        currentCompany()->update([
            'tax_reserve_enabled' => $data['enabled'],
            'tax_reserve_rate' => $data['rate'],
            'tax_reserve_deduct_expense_tax' => $data['deduct_expense_tax'],
            'tax_reserve_basis' => $data['basis'],
            'tax_reserve_reminder_day' => $data['reminder_day'] ?? null,
        ]);
        $audit->record('tax_reserve.settings_changed', currentCompany(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tax_reserve.settings.saved')]);

        return back();
    }

    /**
     * "Mark transfer": the amount moved to the tax account for a month, the date and a note.
     */
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->owner($request);
        $company = currentCompany();

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'transferred_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'amount' => __('tax_reserve.transfer.amount'),
            'transferred_on' => __('tax_reserve.transfer.date'),
        ]);

        $transfer = TaxReserveTransfer::query()->create([
            'month' => CarbonImmutable::createFromFormat('!Y-m', $data['month'])->toDateString(),
            'amount' => Currencies::toMinor($data['amount'], $company->currency),
            'currency' => $company->currency,
            'transferred_on' => $data['transferred_on'],
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $audit->record('tax_reserve.transfer_marked', $transfer, ['month' => $data['month'], 'amount' => $transfer->amount]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tax_reserve.transfer.saved')]);

        return back();
    }

    /**
     * A transfer marked by mistake.
     */
    public function destroy(Request $request, TaxReserveTransfer $transfer, AuditLogger $audit): RedirectResponse
    {
        $this->owner($request);

        $audit->record('tax_reserve.transfer_removed', $transfer, ['month' => $transfer->month->format('Y-m'), 'amount' => $transfer->amount]);
        $transfer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tax_reserve.transfer.removed')]);

        return back();
    }

    /**
     * For the bookkeeper: per month the taxes, the income tax, the total, what was moved and what is left.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->owner($request);
        $company = currentCompany();
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year']
            ?? CarbonImmutable::now($company->timezone)->year);
        $user = $request->user();

        return response()->streamDownload(function () use ($year, $user, $company) {
            $out = fopen('php://output', 'w');
            $minor = fn (int $amount) => number_format($amount / Currencies::factor($company->currency), Currencies::decimals($company->currency), '.', '');
            fputcsv($out, ['Month', 'Sales taxes', 'Sales taxes total', 'Net profit', 'Income tax rate %', 'Income tax', 'To move', 'Moved', 'Left', 'Currency']);

            for ($m = 1; $m <= 12; $m++) {
                $month = CarbonImmutable::create($year, $m, 1, 0, 0, 0, $company->timezone);

                if ($month->isAfter(CarbonImmutable::now($company->timezone))) {
                    break;
                }

                $r = TaxReserve::month($month, $user);
                fputcsv($out, [
                    $month->format('Y-m'),
                    implode('; ', array_map(fn (array $tax) => $tax['name'].': '.$minor($tax['amount']), $r['taxes'])),
                    $minor(array_sum(array_column($r['taxes'], 'amount'))),
                    $minor($r['profit']),
                    $r['rate'],
                    $minor($r['income_tax']),
                    $minor($r['total']),
                    $minor($r['transferred']),
                    $minor($r['left']),
                    $r['currency'],
                ]);
            }
            fclose($out);
        }, "tax-reserve-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user()->hasRole(UserRole::Owner), 403);
    }

    private function month(Request $request): ?CarbonImmutable
    {
        $value = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;

        return $value ? CarbonImmutable::createFromFormat('!Y-m', $value, currentCompany()->timezone)->startOfMonth() : null;
    }
}
