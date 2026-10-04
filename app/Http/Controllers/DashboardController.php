<?php

namespace App\Http\Controllers;

use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The working day at a glance: today's visits and unpaid invoices (unfinished jobs have their own bar on every screen).
 * First-run steps are shown only until the company has its first job.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $company = currentCompany();
        $user = $request->user();
        $visibleJobs = ServiceJob::query()->visibleTo($user)->select('id');
        $today = CarbonImmutable::now($company->timezone);
        $canSeeInvoices = $user->can('viewAny', Invoice::class);
        $unpaid = Invoice::query()->whereIn('service_job_id', $visibleJobs)->outstanding();

        return Inertia::render('dashboard', [
            'companyName' => $company->name,
            'hasBrand' => Brand::query()->where('is_active', true)->exists(),
            'isNew' => ! ServiceJob::query()->exists(),
            'today' => [
                'date' => $today->toDateString(),
                'visits' => JobVisit::query()->whereIn('service_job_id', $visibleJobs)
                    ->whereIn('status', VisitStatus::openValues())
                    ->whereBetween('scheduled_start', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
                    ->count(),
                'unpaid' => $canSeeInvoices ? [
                    'count' => (clone $unpaid)->count(),
                    'totals' => (clone $unpaid)->selectRaw('currency, sum(balance) as amount')->groupBy('currency')->orderBy('currency')
                        ->get()->map(fn ($row) => ['currency' => $row->currency, 'amount' => (int) $row->amount])->values(),
                ] : null,
            ],
        ]);
    }
}
