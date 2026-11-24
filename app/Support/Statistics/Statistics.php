<?php

namespace App\Support\Statistics;

use App\Enums\ApplianceCategory;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\LeadSource;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobPart;
use App\Models\JobStatusChange;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\SquareInvoice;
use App\Support\Billing\JobProfit;
use App\Support\Jobs\PossibleWarranty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statistics (owner's task of November 24), in the company currency and time zone. Money is counted from payments
 * by the date they were received (invoices imported from Square count as Card); jobs by the date they were completed.
 * Deleted jobs and everything deleted with them are left out (soft-deleted rows are not queried).
 *
 * Must run in a tenant context.
 */
class Statistics
{
    public const METHODS = ['e_transfer', 'card', 'cash'];

    private Company $company;

    private string $tz;

    /** @var Collection<int, ServiceJob>|null */
    private ?Collection $jobs = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $payments = null;

    /** @var array<int, array{revenue: int, cost: int}> */
    private array $profit = [];

    public function __construct(private readonly CarbonImmutable $from, private readonly CarbonImmutable $to)
    {
        $this->company = currentCompany();
        $this->tz = $this->company->timezone;
    }

    public static function for(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return (new self($from, $to))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'perDay' => $this->perDay(),
            'money' => $this->money(),
            'appliances' => $this->appliances(),
            'averageTicket' => $this->averageTicket(),
            'brands' => $this->brands(),
            'sources' => $this->sources(),
            'cities' => $this->cities(),
            'repeat' => $this->repeat(),
            'months' => $this->months(),
            'diagnostic' => $this->diagnostic(),
            'timeToComplete' => $this->timeToComplete(),
            'profit' => $this->profitPerJob(),
            'weekdays' => $this->weekdays(),
            'unpaid' => $this->unpaid(),
        ];
    }

    /**
     * Jobs completed in the period (not cancelled), with the local completion date.
     *
     * @return Collection<int, ServiceJob>
     */
    public function completedJobs(): Collection
    {
        return $this->jobs ??= ServiceJob::query()
            ->whereNotIn('status', [JobStatus::Cancelled->value, JobStatus::Draft->value])
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', JobOutcome::Cancelled->value))
            ->whereRaw('coalesce(completed_at, closed_at) between ? and ?', [$this->start(), $this->end()])
            ->with('property')
            ->get()
            ->each(function (ServiceJob $job) {
                $done = CarbonImmutable::instance($job->completed_at ?? $job->closed_at)->setTimezone($this->tz);
                $job->setAttribute('done_on', $done->toDateString());
                $job->setAttribute('done_dow', $done->dayOfWeekIso);
            });
    }

    /**
     * а) Average completed jobs per day: weekdays (Mon–Fri), weekend (Sat–Sun) and the whole week, each divided by
     * the days that had at least one completed job.
     *
     * @return array{total: int, weekday: float|null, weekend: float|null, week: float|null, days: int}
     */
    public function perDay(): array
    {
        $jobs = $this->completedJobs();
        $byDay = $jobs->groupBy('done_on');
        $weekday = $byDay->filter(fn (Collection $day) => $day->first()->done_dow <= 5);
        $weekend = $byDay->filter(fn (Collection $day) => $day->first()->done_dow >= 6);
        $avg = fn (Collection $days) => $days->isEmpty() ? null : round($days->sum(fn (Collection $d) => $d->count()) / $days->count(), 1);

        return [
            'total' => $jobs->count(),
            'weekday' => $avg($weekday),
            'weekend' => $avg($weekend),
            'week' => $avg($byDay),
            'days' => $byDay->count(),
        ];
    }

    /**
     * Payments received in the period (company currency), each with its method group and its taxes by name.
     * Invoices imported from Square count as Card.
     *
     * @return Collection<int, array{date: string, method: string, amount: int, taxes: array<string, int>, invoice: string|null, customer_id: int|null}>
     */
    public function payments(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        $own = $from === null && $to === null;

        if ($own && $this->payments !== null) {
            return $this->payments;
        }

        $start = ($from ?? $this->from)->startOfDay()->shiftTimezone($this->tz)->utc();
        $end = ($to ?? $this->to)->endOfDay()->shiftTimezone($this->tz)->utc();
        $currency = $this->company->currency;

        $rows = Payment::query()->valid()
            ->where('currency', $currency)
            ->whereBetween('received_at', [$start, $end])
            ->where(fn ($q) => $q->whereHas('invoice', fn ($i) => $i->where('status', '!=', InvoiceStatus::Void->value))->orWhereNotNull('estimate_id'))
            ->with(['invoice', 'estimate'])
            ->get()
            ->filter(fn (Payment $p) => $p->invoice !== null ? ! $p->invoice->trashed() : ($p->estimate !== null && ! $p->estimate->trashed()))
            ->map(function (Payment $payment) {
                $document = $payment->invoice ?? $payment->estimate;

                return [
                    'date' => CarbonImmutable::instance($payment->received_at)->setTimezone($this->tz)->toDateString(),
                    'method' => $payment->method->statsGroup(),
                    'method_label' => $payment->method->label(),
                    'amount' => (int) $payment->amount,
                    'taxes' => self::taxShare((int) $payment->amount, (int) $document->total, (array) $document->taxes),
                    'invoice' => $document->number,
                    'customer_id' => $document->customer_id,
                ];
            })
            ->values();

        $square = SquareInvoice::query()->where('currency', $currency)->get()
            ->flatMap(fn (SquareInvoice $invoice) => collect((array) $invoice->payments)
                ->filter(fn (array $p) => ! empty($p['paid_at']))
                ->map(fn (array $p) => [$invoice, $p, CarbonImmutable::parse($p['paid_at'])]))
            ->filter(fn (array $row) => $row[2]->betweenIncluded($start, $end))
            ->map(fn (array $row) => [
                'date' => $row[2]->setTimezone($this->tz)->toDateString(),
                'method' => 'card',
                'method_label' => PaymentMethod::CardTerminal->label(),
                'amount' => (int) $row[1]['amount'],
                'taxes' => self::taxShare((int) $row[1]['amount'], (int) $row[0]->total, (array) $row[0]->taxes),
                'invoice' => $row[0]->number,
                'customer_id' => $row[0]->customer_id,
            ]);

        $all = $rows->concat($square)->sortBy('date')->values();

        return $own ? $this->payments = $all : $all;
    }

    /**
     * The part of each document tax in a payment of $amount out of a document $total.
     *
     * @param  array<int, array<string, mixed>>  $taxes
     * @return array<string, int>
     */
    public static function taxShare(int $amount, int $total, array $taxes): array
    {
        $share = [];

        foreach ($taxes as $tax) {
            $name = (string) ($tax['name'] ?? '');
            if ($name === '' || $total === 0) {
                continue;
            }
            $share[$name] = ($share[$name] ?? 0) + (int) round(((int) ($tax['amount'] ?? 0)) * $amount / $total);
        }

        return $share;
    }

    /**
     * б) Money by method (E-transfer, Card, Cash, and Other for older check / crypto payments) with the total and the
     * taxes in it by name (e.g. GST, PST).
     *
     * @return array{methods: list<array{key: string, amount: int}>, total: int, taxes: list<array{name: string, amount: int}>}
     */
    public function money(): array
    {
        $payments = $this->payments();
        $methods = collect([...self::METHODS, 'other'])
            ->map(fn (string $key) => ['key' => $key, 'amount' => (int) $payments->where('method', $key)->sum('amount')])
            ->reject(fn (array $row) => $row['key'] === 'other' && $row['amount'] === 0)
            ->values()->all();
        $taxes = [];
        foreach ($payments as $payment) {
            foreach ($payment['taxes'] as $name => $amount) {
                $taxes[$name] = ($taxes[$name] ?? 0) + $amount;
            }
        }

        return [
            'methods' => $methods,
            'total' => (int) $payments->sum('amount'),
            'taxes' => collect($taxes)->map(fn (int $amount, string $name) => ['name' => $name, 'amount' => $amount])->values()->all(),
        ];
    }

    /**
     * в) Repairs (not "only diagnostic") by appliance type, most first.
     *
     * @return list<array{key: string, name: string, count: int}>
     */
    public function appliances(): array
    {
        return $this->completedJobs()->reject(fn (ServiceJob $job) => $job->only_diagnostic)
            ->groupBy(fn (ServiceJob $job) => $job->appliance_category?->value ?? '')
            ->map(fn (Collection $group, string $key) => ['key' => $key, 'name' => self::categoryName($key), 'count' => $group->count()])
            ->sortByDesc('count')->values()->all();
    }

    /**
     * Revenue without taxes and cost of a job (JobProfit, every cost counted: the Owner's view).
     *
     * @return array{revenue: int, cost: int}
     */
    public function jobMoney(ServiceJob $job): array
    {
        if (! isset($this->profit[$job->id])) {
            $row = JobProfit::for($job, true);
            $this->profit[$job->id] = ['revenue' => (int) $row['revenue'], 'cost' => (int) $row['cost']];
        }

        return $this->profit[$job->id];
    }

    /**
     * г) Average ticket (invoiced without taxes, jobs with an invoice): overall and by appliance type.
     *
     * @return array{average: int|null, jobs: int, byAppliance: list<array{key: string, name: string, average: int, jobs: int}>}
     */
    public function averageTicket(): array
    {
        $billed = $this->billedJobs();

        return [
            'average' => self::average($billed),
            'jobs' => $billed->count(),
            'byAppliance' => $billed->groupBy(fn (array $row) => $row['job']->appliance_category?->value ?? '')
                ->map(fn (Collection $group, string $key) => ['key' => $key, 'name' => self::categoryName($key), 'average' => self::average($group), 'jobs' => $group->count()])
                ->sortByDesc('average')->values()->all(),
        ];
    }

    /**
     * @return Collection<int, array{job: ServiceJob, revenue: int}>
     */
    private function billedJobs(): Collection
    {
        return $this->completedJobs()
            ->map(fn (ServiceJob $job) => ['job' => $job, 'revenue' => $this->jobMoney($job)['revenue']])
            ->filter(fn (array $row) => $row['revenue'] > 0)
            ->values();
    }

    /**
     * @param  Collection<int, array{revenue: int}>  $rows
     */
    private static function average(Collection $rows): ?int
    {
        return $rows->isEmpty() ? null : (int) round($rows->sum('revenue') / $rows->count());
    }

    /**
     * д) Jobs by appliance brand, most first.
     *
     * @return list<array{name: string|null, count: int}>
     */
    public function brands(): array
    {
        return $this->completedJobs()
            ->groupBy(fn (ServiceJob $job) => $job->appliance_brand ?? '')
            ->map(fn (Collection $group, string $brand) => ['name' => $brand === '' ? null : $brand, 'count' => $group->count()])
            ->sortByDesc('count')->values()->all();
    }

    /**
     * е) New customers (added in the period) and money received, by source.
     *
     * @return list<array{key: string, name: string, customers: int, revenue: int}>
     */
    public function sources(): array
    {
        $group = fn (?LeadSource $source) => $source?->group()->value ?? '';
        $customers = Customer::query()->where('is_walk_in', false)
            ->whereBetween('created_at', [$this->start(), $this->end()])
            ->get(['id', 'lead_source'])
            ->countBy(fn (Customer $c) => $group($c->lead_source));
        $sourceOf = Customer::withTrashed()->whereIn('id', $this->payments()->pluck('customer_id')->filter()->unique())
            ->get(['id', 'lead_source'])->mapWithKeys(fn (Customer $c) => [$c->id => $group($c->lead_source)]);
        $revenue = $this->payments()->groupBy(fn (array $p) => $sourceOf[$p['customer_id']] ?? '')
            ->map(fn (Collection $rows) => (int) $rows->sum('amount'));

        return collect([...array_map(fn (LeadSource $s) => $s->value, LeadSource::offered()), ''])
            ->map(fn (string $key) => [
                'key' => $key,
                'name' => $key === '' ? __('statistics.not_set') : LeadSource::from($key)->label(),
                'customers' => (int) ($customers[$key] ?? 0),
                'revenue' => (int) ($revenue[$key] ?? 0),
            ])
            ->reject(fn (array $row) => $row['customers'] === 0 && $row['revenue'] === 0)
            ->sortByDesc('revenue')->values()->all();
    }

    /**
     * ж) Jobs and average ticket by the city of the job's address.
     *
     * @return list<array{name: string|null, jobs: int, average: int|null}>
     */
    public function cities(): array
    {
        return $this->completedJobs()
            ->groupBy(fn (ServiceJob $job) => mb_convert_case(trim((string) $job->property?->city), MB_CASE_TITLE))
            ->map(function (Collection $group, string $city) {
                $billed = $group->map(fn (ServiceJob $job) => ['revenue' => $this->jobMoney($job)['revenue']])->filter(fn ($r) => $r['revenue'] > 0);

                return ['name' => $city === '' ? null : $city, 'jobs' => $group->count(), 'average' => self::average($billed)];
            })
            ->sortByDesc('jobs')->values()->all();
    }

    /**
     * з) Share of repeat customers (customers of the period's jobs who had an earlier job) and the jobs that were a
     * possible warranty (the customer had a job finished within the warranty window before this one was booked).
     *
     * @return array{customers: int, repeat: int, rate: float|null, possibleWarranty: int}
     */
    public function repeat(): array
    {
        $jobs = $this->completedJobs();
        $customers = $jobs->groupBy('customer_id');
        $repeat = $customers->filter(fn (Collection $own, $customerId) => ServiceJob::query()
            ->where('customer_id', $customerId)
            ->whereNotIn('id', $own->pluck('id'))
            ->where('created_at', '<', $own->min('created_at'))
            ->exists())->count();
        $warranty = $jobs->filter(fn (ServiceJob $job) => PossibleWarranty::for(
            $job->customer_id, $this->company, $job->id, CarbonImmutable::instance($job->created_at),
        ) !== null)->count();

        return [
            'customers' => $customers->count(),
            'repeat' => $repeat,
            'rate' => $customers->isEmpty() ? null : round($repeat / $customers->count() * 100, 1),
            'possibleWarranty' => $warranty,
        ];
    }

    /**
     * и) Money received in each of the 12 months up to the period's end, with the change against the month before.
     *
     * @return list<array{month: string, amount: int, change: float|null}>
     */
    public function months(): array
    {
        $last = $this->to->startOfMonth();
        $first = $last->subMonthsNoOverflow(12);
        $byMonth = $this->payments($first, $last->endOfMonth())->groupBy(fn (array $p) => substr($p['date'], 0, 7))
            ->map(fn (Collection $rows) => (int) $rows->sum('amount'));
        $rows = [];
        $previous = (int) ($byMonth[$first->format('Y-m')] ?? 0);

        for ($month = $first->addMonthNoOverflow(); $month <= $last; $month = $month->addMonthNoOverflow()) {
            $amount = (int) ($byMonth[$month->format('Y-m')] ?? 0);
            $rows[] = [
                'month' => $month->toDateString(),
                'amount' => $amount,
                'change' => self::change($previous, $amount),
            ];
            $previous = $amount;
        }

        return $rows;
    }

    public static function change(int $previous, int $current): ?float
    {
        return $previous === 0 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }

    /**
     * к) Only diagnostic against repaired: count and share.
     *
     * @return array{diagnostic: int, repair: int, diagnosticRate: float|null, repairRate: float|null}
     */
    public function diagnostic(): array
    {
        $jobs = $this->completedJobs();
        $diagnostic = $jobs->filter(fn (ServiceJob $job) => $job->only_diagnostic)->count();
        $repair = $jobs->count() - $diagnostic;
        $rate = fn (int $n) => $jobs->isEmpty() ? null : round($n / $jobs->count() * 100, 1);

        return ['diagnostic' => $diagnostic, 'repair' => $repair, 'diagnosticRate' => $rate($diagnostic), 'repairRate' => $rate($repair)];
    }

    /**
     * л) Average days from booking to completion; jobs that waited for parts apart.
     *
     * @return array{all: float|null, withParts: float|null, withoutParts: float|null, withPartsJobs: int, withoutPartsJobs: int}
     */
    public function timeToComplete(): array
    {
        $jobs = $this->completedJobs();
        $ids = $jobs->pluck('id');
        $waited = JobStatusChange::query()->whereIn('service_job_id', $ids)
            ->whereIn('to_status', [JobStatus::NeedToOrderParts->value, JobStatus::WaitingForParts->value, JobStatus::PartsReady->value])
            ->pluck('service_job_id')
            ->merge(JobPart::query()->whereIn('service_job_id', $ids)->pluck('service_job_id'))
            ->merge($jobs->filter(fn (ServiceJob $job) => $job->part_ordered_on !== null || filled($job->part_name))->pluck('id'))
            ->unique()->flip();
        $days = fn (ServiceJob $job) => CarbonImmutable::instance($job->created_at)->diffInSeconds(CarbonImmutable::instance($job->completed_at ?? $job->closed_at)) / 86400;
        $avg = fn (Collection $group) => $group->isEmpty() ? null : round($group->avg($days), 1);
        [$with, $without] = $jobs->partition(fn (ServiceJob $job) => isset($waited[$job->id]));

        return [
            'all' => $avg($jobs),
            'withParts' => $avg($with),
            'withoutParts' => $avg($without),
            'withPartsJobs' => $with->count(),
            'withoutPartsJobs' => $without->count(),
        ];
    }

    /**
     * м) Profit of a job: invoiced without taxes − the cost of the parts tied to it (invoice lines, job costs and
     * job expenses). Average and total over the jobs completed in the period that have an invoice.
     *
     * @return array{total: int, average: int|null, jobs: int}
     */
    public function profitPerJob(): array
    {
        $rows = $this->completedJobs()->map(fn (ServiceJob $job) => $this->jobMoney($job))->filter(fn (array $m) => $m['revenue'] > 0);
        $total = (int) $rows->sum(fn (array $m) => $m['revenue'] - $m['cost']);

        return ['total' => $total, 'average' => $rows->isEmpty() ? null : (int) round($total / $rows->count()), 'jobs' => $rows->count()];
    }

    /**
     * н) Completed jobs by day of the week, Monday first.
     *
     * @return list<array{day: int, jobs: int}>
     */
    public function weekdays(): array
    {
        $counts = $this->completedJobs()->countBy('done_dow');

        return array_map(fn (int $day) => ['day' => $day, 'jobs' => (int) ($counts[$day] ?? 0)], range(1, 7));
    }

    /**
     * о) Invoices issued in the period that are still unpaid: count and balance.
     *
     * @return array{count: int, amount: int}
     */
    public function unpaid(): array
    {
        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->where('currency', $this->company->currency)
            ->where('balance', '>', 0)
            ->whereBetween('issued_on', [$this->from->toDateString(), $this->to->toDateString()])
            ->get(['id', 'balance']);

        return ['count' => $invoices->count(), 'amount' => (int) $invoices->sum('balance')];
    }

    private static function categoryName(string $key): string
    {
        return $key === '' ? __('statistics.not_set') : (ApplianceCategory::tryFrom($key)?->label() ?? $key);
    }

    private function start(): CarbonImmutable
    {
        return $this->from->startOfDay()->shiftTimezone($this->tz)->utc();
    }

    private function end(): CarbonImmutable
    {
        return $this->to->endOfDay()->shiftTimezone($this->tz)->utc();
    }
}
