<?php

namespace App\Http\Controllers\Statistics;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\ReportController;
use App\Support\Locale\Currencies;
use App\Support\Statistics\Statistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * More → Statistics (owner's task of November 24), for the Owner only: the period on top (This week, This month,
 * Last month, This year, Last year, Custom), then the blocks of App\Support\Statistics\Statistics and the earlier
 * reports (profit, revenue by brand / technician / job type, callbacks, no-charge jobs, exports).
 */
class StatisticsController extends Controller
{
    public const PERIODS = ['this_week', 'this_month', 'last_month', 'this_year', 'last_year', 'custom'];

    public function index(Request $request, ReportController $reports): Response
    {
        $this->authorizeOwner($request);
        [$from, $to, $period] = self::period($request);
        $company = currentCompany();

        return Inertia::render('statistics/index', [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => CarbonImmutable::now($company->timezone)->toDateString(),
            'currency' => $company->currency,
            'stats' => Statistics::for($from, $to),
            'reports' => $reports->sections($request, $from, $to),
        ]);
    }

    /**
     * б) The money of the period as CSV: one row per payment with its method and taxes.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeOwner($request);
        [$from, $to] = self::period($request);
        $payments = (new Statistics($from, $to))->payments();
        $currency = currentCompany()->currency;
        $taxNames = $payments->flatMap(fn (array $p) => array_keys($p['taxes']))->unique()->values()->all();
        $factor = Currencies::factor($currency);
        $major = fn (int $amount) => number_format($amount / $factor, (int) round(log10($factor)), '.', '');

        return response()->streamDownload(function () use ($payments, $taxNames, $major, $currency) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Invoice', 'Method', 'Amount', ...$taxNames, 'Currency']);

            foreach ($payments as $payment) {
                fputcsv($out, [
                    $payment['date'],
                    $payment['invoice'],
                    __('statistics.methods.'.$payment['method']),
                    $major($payment['amount']),
                    ...array_map(fn (string $name) => $major((int) ($payment['taxes'][$name] ?? 0)), $taxNames),
                    $currency,
                ]);
            }

            fclose($out);
        }, 'money-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The period chosen on top, in the company's time zone (This month by default; weeks start on Monday).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    public static function period(Request $request): array
    {
        $tz = currentCompany()->timezone;
        $data = $request->validate([
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $now = CarbonImmutable::now($tz)->startOfDay();
        $period = $data['period'] ?? (isset($data['from']) || isset($data['to']) ? 'custom' : 'this_month');

        [$from, $to] = match ($period) {
            'this_week' => [$now->startOfWeek(CarbonImmutable::MONDAY), $now->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()->startOfDay()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()->startOfDay()],
            'custom' => [
                isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz) : $now->startOfMonth(),
                isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz) : $now,
            ],
            default => [$now->startOfMonth(), $now->endOfMonth()->startOfDay()],
        };

        Validator::make(['from' => $from->toDateString(), 'to' => $to->toDateString()], ['to' => ['after_or_equal:from']])->validate();

        return [$from, $to, $period];
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()->hasRole(UserRole::Owner), 403);
    }
}
