<?php

namespace App\Http\Controllers\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\UserRole;
use App\Enums\VisitType;
use App\Http\Controllers\Controller;
use App\Models\InvoiceItem;
use App\Models\JobCostItem;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\SupplierReceipt;
use App\Support\Billing\JobProfit;
use App\Support\Locale\Currencies;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Reports for the office (period = jobs closed in it, company time zone): profit and margin by technician and
 * appliance type, warranty callback rate by technician, brand and appliance type, no-charge jobs; expenses as CSV
 * and the supplier receipts as a ZIP for the bookkeeper. Amounts in the company currency.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeOffice($request);
        [$from, $to] = $this->period($request);

        $jobs = $this->closedJobs($from, $to);
        $rows = $jobs->map(fn (ServiceJob $job) => [
            'job' => $job,
            'technician' => $this->technician($job),
            'appliance' => $job->appliances->first()?->type->label() ?? __('reports.no_appliance'),
            'brand' => $job->brand?->name ?? '—',
            ...JobProfit::for($job),
        ]);

        $billable = $rows->filter(fn (array $r) => ! in_array($r['job']->outcome, [JobOutcome::Cancelled], true));
        $originals = $rows->filter(fn (array $r) => $r['job']->visit_type !== VisitType::Callback && $r['job']->outcome !== JobOutcome::Cancelled);
        $callbacks = ServiceJob::query()->withTrashed()
            ->where('visit_type', VisitType::Callback->value)
            ->whereIn('previous_job_id', $originals->map(fn (array $r) => $r['job']->id))
            ->pluck('previous_job_id')
            ->countBy();
        $noCharge = $rows->filter(fn (array $r) => $r['job']->outcome === JobOutcome::NoCharge);

        return Inertia::render('reports/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => currentCompany()->currency,
            'totals' => $this->sum($billable),
            'byTechnician' => $this->groupProfit($billable, 'technician'),
            'byAppliance' => $this->groupProfit($billable, 'appliance'),
            'callbacks' => [
                'total' => $this->rate($originals, $callbacks),
                'byTechnician' => $this->groupRate($originals, $callbacks, 'technician'),
                'byBrand' => $this->groupRate($originals, $callbacks, 'brand'),
                'byAppliance' => $this->groupRate($originals, $callbacks, 'appliance'),
            ],
            'noCharge' => [
                'count' => $noCharge->count(),
                'loss' => -$noCharge->sum('profit'),
                'byTechnician' => $noCharge->groupBy('technician')->map(fn (Collection $g, string $name) => [
                    'name' => $name, 'count' => $g->count(), 'loss' => -$g->sum('profit'),
                ])->values(),
            ],
        ]);
    }

    /**
     * Every cost line of jobs closed in the period: invoice lines with a cost (billed or internal) and job cost lines.
     */
    public function expenses(Request $request): StreamedResponse
    {
        $this->authorizeOffice($request);
        [$from, $to] = $this->period($request);
        $jobs = $this->closedJobs($from, $to)->keyBy('id');

        $lines = collect();
        InvoiceItem::query()
            ->whereNotNull('unit_cost')
            ->whereHas('invoice', fn ($q) => $q->whereIn('service_job_id', $jobs->keys())->where('status', '!=', InvoiceStatus::Void->value))
            ->with('invoice')
            ->get()
            ->each(fn (InvoiceItem $item) => $lines->push([$jobs[$item->invoice->service_job_id], $item, $item->invoice->number, $item->invoice->currency]));
        JobCostItem::query()->whereIn('service_job_id', $jobs->keys())->get()
            ->each(fn (JobCostItem $item) => $lines->push([$jobs[$item->service_job_id], $item, '', $item->currency]));

        $name = 'expenses-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date closed', 'Job', 'Invoice', 'Kind', 'Description', 'Part number', 'Supplier', 'Quantity', 'Unit',
                'Unit cost', 'Supplier tax recoverable', 'Supplier tax not recoverable', 'Total cost', 'Billed to customer', 'Currency']);

            foreach ($lines as [$job, $item, $invoice, $currency]) {
                $taxes = collect($item->supplier_taxes ?? []);
                $factor = Currencies::factor($currency);
                // Plain numbers in major units (e.g. 12.50) for spreadsheets.
                $minor = fn (int $amount) => number_format($amount / $factor, (int) round(log10($factor)), '.', '');
                fputcsv($out, [
                    $job->closed_at?->setTimezone(currentCompany()->timezone)->toDateString(),
                    $job->number,
                    $invoice,
                    $item->kind->value,
                    $item->description,
                    $item->part_number,
                    $item->supplier,
                    (string) $item->quantity,
                    $item->unit,
                    $minor((int) $item->unit_cost),
                    $minor((int) $taxes->where('recoverable', true)->sum('amount')),
                    $minor((int) $taxes->where('recoverable', false)->sum('amount')),
                    $minor($item->totalCost()),
                    $item instanceof InvoiceItem && $item->bill_to_customer ? 'yes' : 'no',
                    $currency,
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * The supplier receipts of jobs closed in the period, as a ZIP (file names start with the job numbers).
     */
    public function receipts(Request $request): BinaryFileResponse
    {
        $this->authorizeOffice($request);
        [$from, $to] = $this->period($request);
        $jobIds = $this->closedJobs($from, $to)->pluck('id');

        $receipts = SupplierReceipt::query()->whereHas('jobs', fn ($q) => $q->whereIn('service_jobs.id', $jobIds))->with('jobs')->get();
        $path = tempnam(sys_get_temp_dir(), 'receipts');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($receipts as $receipt) {
            $contents = PrivateMedia::disk()->get($receipt->path);

            if ($contents !== null) {
                $numbers = $receipt->jobs->pluck('number')->implode('-');
                $zip->addFromString("job-{$numbers}-{$receipt->id}-".preg_replace('/[^A-Za-z0-9._-]+/', '-', $receipt->original_name), $contents);
            }
        }

        if ($receipts->isEmpty()) {
            $zip->addFromString('README.txt', __('reports.no_receipts'));
        }

        $zip->close();

        return response()->download($path, 'receipts-'.$from->toDateString().'-'.$to->toDateString().'.zip')->deleteFileAfterSend();
    }

    /**
     * @return Collection<int, ServiceJob>
     */
    private function closedJobs(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $tz = currentCompany()->timezone;

        return ServiceJob::query()
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from->startOfDay()->shiftTimezone($tz)->utc(), $to->endOfDay()->shiftTimezone($tz)->utc()])
            ->with(['appliances', 'brand', 'visits.assignees'])
            ->limit(5000)
            ->get();
    }

    /**
     * The technician of a job: the first person on its last visit.
     */
    private function technician(ServiceJob $job): string
    {
        /** @var JobVisit|null $visit */
        $visit = $job->visits->filter(fn (JobVisit $v) => $v->started_at !== null)->last() ?? $job->visits->last();

        return $visit?->assignees->first()?->name ?? __('reports.unassigned');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{jobs: int, revenue: int, cost: int, fees: int, profit: int, margin: float|null}
     */
    private function sum(Collection $rows): array
    {
        $revenue = (int) $rows->sum('revenue');
        $profit = (int) $rows->sum('profit');

        return [
            'jobs' => $rows->count(),
            'revenue' => $revenue,
            'cost' => (int) $rows->sum('cost'),
            'fees' => (int) $rows->sum('fees'),
            'profit' => $profit,
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupProfit(Collection $rows, string $key): array
    {
        return $rows->groupBy($key)->map(fn (Collection $g, string $name) => ['name' => $name, ...$this->sum($g)])
            ->sortByDesc('profit')->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $originals
     * @param  Collection<int, int>  $callbacks  Callbacks per original job id
     * @return array{jobs: int, callbacks: int, rate: float|null}
     */
    private function rate(Collection $originals, Collection $callbacks): array
    {
        $count = $originals->sum(fn (array $r) => $callbacks[$r['job']->id] ?? 0);

        return [
            'jobs' => $originals->count(),
            'callbacks' => (int) $count,
            'rate' => $originals->count() > 0 ? round($count / $originals->count() * 100, 1) : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $originals
     * @param  Collection<int, int>  $callbacks
     * @return list<array<string, mixed>>
     */
    private function groupRate(Collection $originals, Collection $callbacks, string $key): array
    {
        return $originals->groupBy($key)->map(fn (Collection $g, string $name) => ['name' => $name, ...$this->rate($g, $callbacks)])
            ->sortByDesc('rate')->values()->all();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $tz = currentCompany()->timezone;
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            ? CarbonImmutable::parse((string) $request->query($key), $tz)
            : null;
        $now = CarbonImmutable::now($tz);

        return [$date('from') ?? $now->startOfMonth(), $date('to') ?? $now];
    }

    private function authorizeOffice(Request $request): void
    {
        abort_unless($request->user()->hasRole(UserRole::Owner, UserRole::Admin), 403);
    }
}
