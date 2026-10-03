<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CreateInvoicePaymentLink;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Billing\VoidBillingRecord;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\VisitType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Messaging\MessagingPresenter;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Payments\PaymentProviders;
use App\Services\AuditLogger;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\Warranties;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function start(Request $request): Response|RedirectResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $search = trim((string) $request->query('search', ''));
        $base = ServiceJob::query()->visibleTo($request->user());

        if (! (clone $base)->exists()) {
            return to_route('jobs.create', ['invoice' => 1]);
        }

        return Inertia::render('invoices/start', [
            'search' => $search,
            'jobs' => $base->search($search)->with('customer')->orderByDesc('id')->limit(25)->get()
                ->filter(fn (ServiceJob $job) => Gate::allows('work', $job))
                ->map(fn (ServiceJob $job) => [
                    'id' => $job->id,
                    'number' => $job->number,
                    'customer' => $job->customer->display_name,
                    'description' => $job->description,
                ])->values(),
        ]);
    }

    /**
     * All invoices of the brands the office user works for, outstanding first by default.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        $user = $request->user();
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', 'outstanding'),
        ];
        $status = InvoiceStatus::tryFrom($filters['status']);
        $visibleJobs = ServiceJob::query()->visibleTo($user)->select('id');

        $base = Invoice::query()->whereIn('service_job_id', $visibleJobs);

        $invoices = (clone $base)
            ->when($filters['status'] === 'outstanding', fn ($q) => $q->outstanding())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters) {
                $like = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(fn (Builder $w) => $w
                    ->where('number', 'ilike', $like)
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('display_name', 'ilike', $like))
                    ->orWhereHas('job', fn (Builder $j) => $j->search($filters['search'])));
            })
            ->with('customer')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => BillingPresenter::row($invoice));

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'statuses' => InvoiceStatus::options(),
            // One sum per currency: documents keep the currency they were created in.
            'outstandingTotals' => (clone $base)->outstanding()
                ->selectRaw('currency, sum(balance) as amount')
                ->groupBy('currency')
                ->orderBy('currency')
                ->get()
                ->map(fn ($row) => ['currency' => $row->currency, 'amount' => (int) $row->amount])
                ->values(),
        ]);
    }

    public function create(Request $request, ServiceJob $job): Response
    {
        Gate::authorize('work', $job);

        // Due date from the customer's payment terms (or the company default), editable on the form.
        $terms = $job->customer()->withTrashed()->first()?->paymentTerms() ?? currentCompany()->default_payment_terms;
        $today = CarbonImmutable::now(currentCompany()->timezone)->startOfDay();

        return Inertia::render('billing/form', [
            'kind' => 'invoice',
            'document' => null,
            'job' => BillingPresenter::job($job),
            'taxRates' => BillingPresenter::taxOptions(),
            'services' => BillingPresenter::serviceOptions($job->brand_id),
            'lineSetup' => BillingPresenter::lineSetup(),
            'today' => $today->format('Y-m-d'),
            'defaultDueOn' => $terms->dueOn($today)->format('Y-m-d'),
            'paymentTerms' => $terms->label(),
            // "Invoice diagnosis only" after the customer declined the repair: one line, the diagnostic fee.
            'prefillItems' => $request->boolean('diagnosis') ? [self::diagnosisLine()] : self::callbackLines($job),
        ]);
    }

    /**
     * The diagnostic fee from the price book service picked in Company settings (price only in the company currency),
     * or an empty-priced line to fill in.
     *
     * @return array{description: string, unit_price: int|null, taxable: bool}
     */
    /**
     * First invoice of a warranty callback: the original job's lines, free while their warranty runs on the visit day,
     * at the original price otherwise (the technician can change them).
     *
     * @return list<array<string, mixed>>|null
     */
    public static function callbackLines(ServiceJob $job): ?array
    {
        if ($job->visit_type !== VisitType::Callback || $job->previous_job_id === null || $job->invoices()->exists()) {
            return null;
        }

        $original = $job->previousJob()->first();
        $visit = $job->visits()->first();
        $day = CarbonImmutable::parse(($visit?->scheduled_start ?? now())->setTimezone(currentCompany()->timezone)->format('Y-m-d'));
        $lines = $original ? Warranties::onDate($original, $day) : [];

        return $lines === [] ? null : array_map(fn (array $line) => [
            'description' => $line['description'],
            'unit_price' => $line['active'] ? 0 : $line['unit_price'],
            'taxable' => $line['taxable'],
            'kind' => $line['kind'],
            'quantity' => rtrim(rtrim($line['quantity'], '0'), '.'),
        ], $lines);
    }

    public static function diagnosisLine(): array
    {
        $company = currentCompany();
        $service = $company->diagnostic_service_id ? Service::query()->find($company->diagnostic_service_id) : null;

        return [
            'description' => $service ? collect([$service->name, $service->description])->filter()->implode(' — ') : __('jobs.diagnosis_line'),
            'unit_price' => $service?->unit_price,
            'taxable' => $service?->taxable ?? true,
        ];
    }

    public function store(DocumentRequest $request, ServiceJob $job, SaveBillingDocument $save): RedirectResponse
    {
        Gate::authorize('work', $job);

        $invoice = $save->createInvoice($job, $request->document(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoices.created', ['number' => $invoice->number])]);

        return to_route('invoices.show', $invoice);
    }

    public function show(Invoice $invoice, PaymentProviders $providers, CreateInvoicePaymentLink $links): Response
    {
        Gate::authorize('view', $invoice);

        return Inertia::render('billing/show', [
            'document' => BillingPresenter::document($invoice),
            'can' => [
                'update' => Gate::allows('update', $invoice),
                'recordPayment' => Gate::allows('recordPayment', $invoice),
                'void' => Gate::allows('void', $invoice),
                'voidPayments' => Gate::allows('update', $invoice->job),
                'refund' => Gate::allows('refund', $invoice),
            ],
            'paymentMethods' => PaymentMethod::manualOptions(currentCompany()),
            'today' => $this->today(),
            'online' => $this->online($invoice, $providers, $links),
            'delivery' => BillingPresenter::delivery($invoice),
            'sms' => $invoice->isVoid() ? null : app(MessagingPresenter::class)->forDocument($invoice),
        ]);
    }

    /**
     * Online payment (SPEC §7.6): the company's provider, if ready, and the current link for the balance.
     *
     * @return array{provider: string, link: array{url: string, amount: int, currency: string}|null}|null
     */
    private function online(Invoice $invoice, PaymentProviders $providers, CreateInvoicePaymentLink $links): ?array
    {
        $provider = $providers->readyFor(currentCompany());

        if ($provider === null || $invoice->isVoid() || $invoice->balance <= 0 || Gate::denies('recordPayment', $invoice)) {
            return null;
        }

        $link = $links->current($invoice, $provider->key());

        return [
            'provider' => $provider->label(),
            'link' => $link && $link->amount === $invoice->balance
                ? ['url' => $link->url, 'amount' => $link->amount, 'currency' => $link->currency]
                : null,
        ];
    }

    public function edit(Invoice $invoice): Response
    {
        Gate::authorize('update', $invoice);

        return Inertia::render('billing/form', [
            'kind' => 'invoice',
            'document' => BillingPresenter::document($invoice),
            'job' => BillingPresenter::job($invoice->job),
            'taxRates' => BillingPresenter::taxOptions($invoice->taxes),
            'services' => BillingPresenter::serviceOptions($invoice->brand_id),
            'lineSetup' => BillingPresenter::lineSetup(),
            'today' => $this->today(),
        ]);
    }

    public function update(DocumentRequest $request, Invoice $invoice, SaveBillingDocument $save, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $invoice);

        $before = $invoice->total;
        $save->update($invoice, $request->document(), $request->user());

        // Changing an invoice that already has money on it is worth a trace.
        if ($invoice->amount_paid > 0 && $before !== $invoice->total) {
            $audit->record('invoice.total_changed', $invoice, ['number' => $invoice->number, 'from' => $before, 'to' => $invoice->total]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoices.updated')]);

        return to_route('invoices.show', $invoice);
    }

    public function void(Request $request, Invoice $invoice, VoidBillingRecord $void): RedirectResponse
    {
        Gate::authorize('void', $invoice);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $void->invoice($invoice, $request->user(), $reason);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoices.voided')]);

        return to_route('invoices.show', $invoice);
    }

    private function today(): string
    {
        return CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d');
    }
}
