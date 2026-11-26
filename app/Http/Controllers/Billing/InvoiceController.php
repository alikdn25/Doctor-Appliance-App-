<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CreateInvoicePaymentLink;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Billing\VoidBillingRecord;
use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\VisitType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Messaging\MessagingPresenter;
use App\Messaging\ReviewRequests;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceAttachment;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Payments\PaymentProviders;
use App\Services\AuditLogger;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\RefundAmounts;
use App\Support\Billing\Warranties;
use App\Support\Jobs\JobPresenter;
use App\Support\Locale\Currencies;
use App\Support\Payments\PaidCustomer;
use App\Support\PrivateMedia;
use App\Support\Ui\SavedToast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // Enough to tell two jobs of one customer apart: date, address, status, technicians, invoices.
            'jobs' => $base->search($search)->with(['customer', 'property', 'assignees', 'visits.assignees', 'invoices'])->orderByDesc('id')->limit(25)->get()
                ->filter(fn (ServiceJob $job) => Gate::allows('work', $job))
                ->map(fn (ServiceJob $job) => [
                    'id' => $job->id,
                    'number' => $job->number,
                    'customer' => $job->customer->display_name,
                    'description' => $job->description,
                    'created_at' => JobPresenter::iso($job->created_at),
                    'address' => $job->property?->fullAddress(),
                    'status_label' => $job->status->label(),
                    'technicians' => $job->assignees->merge($job->visits->flatMap->assignees)->pluck('name')->unique()->values(),
                    'invoices' => $job->invoices->reject(fn (Invoice $invoice) => $invoice->isVoid())->pluck('number')->values(),
                ])->values(),
        ]);
    }

    /**
     * All invoices of the brands the office user works for (owner's task of November 26): Unpaid (default) · Paid ·
     * Refunded · All, Overdue from its total on top. Paid lists every paid invoice, newest payment first — charges
     * without a booking ("+" → Charge reader / New charge) too. A search looks through every status: name, phone
     * (last 4 digits or any format), address, amount, invoice number, the card's last 4 digits.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        $user = $request->user();
        $status = (string) $request->query('status', 'unpaid');
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => match ($status) {
                'outstanding' => 'unpaid',
                'paid', 'refunded', 'all', 'overdue', 'unpaid' => $status,
                default => InvoiceStatus::tryFrom($status) !== null ? $status : 'unpaid',
            },
        ];
        $exact = InvoiceStatus::tryFrom($filters['status']);
        [$sort, $dir] = self::sorting($request, ['number', 'customer', 'issued', 'due', 'total', 'balance', 'status', 'paid'], $filters['status'] === 'paid' ? 'paid' : 'issued', 'desc');
        $filters += ['sort' => $sort, 'dir' => $dir];
        $visibleJobs = ServiceJob::query()->visibleTo($user)->select('id');
        $searching = $filters['search'] !== '';
        $paidAt = '(select max(p.received_at) from payments p where p.invoice_id = invoices.id and p.voided_at is null and p.amount > 0)';

        $base = Invoice::query()->whereIn('service_job_id', $visibleJobs);

        $invoices = (clone $base)
            // A search looks through every status.
            ->when(! $searching && $filters['status'] === 'unpaid', fn ($q) => $q->outstanding())
            ->when(! $searching && $filters['status'] === 'overdue', fn ($q) => $q->overdue())
            ->when(! $searching && $filters['status'] === 'paid', fn ($q) => $q->where('status', InvoiceStatus::Paid->value))
            ->when(! $searching && $filters['status'] === 'refunded', fn ($q) => $q->whereIn('status', [InvoiceStatus::Refunded->value, InvoiceStatus::PartiallyRefunded->value]))
            ->when(! $searching && $exact, fn ($q) => $q->where('status', $exact))
            ->when($searching, fn (Builder $q) => self::search($q, $filters['search']))
            ->with(['customer.primaryPhone', 'items', 'payments'])
            ->when($sort === 'paid', fn ($q) => $q->orderByRaw("{$paidAt} {$dir} nulls last"))
            ->when($sort === 'number', fn ($q) => $q->orderBy('number', $dir))
            ->when($sort === 'customer', fn ($q) => $q->orderBy(
                Customer::query()->withTrashed()->select('display_name')->whereColumn('customers.id', 'invoices.customer_id')->limit(1),
                $dir,
            ))
            ->when($sort === 'due', fn ($q) => $q->orderByRaw('due_on '.($dir === 'asc' ? 'asc nulls last' : 'desc nulls last')))
            ->when($sort === 'total', fn ($q) => $q->orderBy('total', $dir))
            ->when($sort === 'balance', fn ($q) => $q->orderBy('balance', $dir))
            ->when($sort === 'status', fn ($q) => $q->orderBy('status', $dir))
            ->orderBy('issued_on', $sort === 'issued' ? $dir : 'desc')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => [...BillingPresenter::row($invoice), ...self::paidDetails($invoice)]);

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'hasInvoices' => (clone $base)->exists(),
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
            'overdueTotals' => (clone $base)->overdue()
                ->selectRaw('currency, sum(balance) as amount')
                ->groupBy('currency')
                ->orderBy('currency')
                ->get()
                ->map(fn ($row) => ['currency' => $row->currency, 'amount' => (int) $row->amount])
                ->values(),
        ]);
    }

    /**
     * Invoices search (every status): number, customer's name, the job's search (phone in any format or its last 4
     * digits, address, appliance …), the total ("120", "120.50", "$120.50"), the card's last 4 digits.
     *
     * @param  Builder<Invoice>  $query
     */
    private static function search(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';
        // An amount typed alone, with or without a currency sign.
        $amount = preg_match('/^\D{0,3}\s*(\d+(?:[.,]\d{1,2})?)\s*$/u', $search, $m) === 1
            ? Currencies::toMinor(str_replace(',', '.', $m[1]), currentCompany()->currency)
            : null;
        $last4 = preg_match('/^\d{4}$/', $search) === 1 ? $search : null;

        $query->where(fn (Builder $w) => $w
            ->where('number', 'ilike', $like)
            ->orWhereHas('customer', fn (Builder $c) => $c->withTrashed()->where('display_name', 'ilike', $like))
            ->orWhereHas('job', fn (Builder $j) => $j->withTrashed()->search($search, false))
            ->when($amount !== null && $amount > 0, fn (Builder $a) => $a->orWhere('total', $amount))
            ->when($last4 !== null, fn (Builder $c) => $c->orWhereHas('payments', fn (Builder $p) => $p->where('card_last4', $last4))));
    }

    /**
     * What a paid row shows (owner's task of November 26): the customer's phone, what was done (the first line), how it
     * was paid (Card ····1234 / Cash / E-transfer) and when, and the Walk-in mark.
     *
     * @return array<string, mixed>
     */
    private static function paidDetails(Invoice $invoice): array
    {
        $payment = $invoice->payments->whereNull('voided_at')->where('amount', '>', 0)->sortByDesc('received_at')->first();

        return [
            'walk_in' => (bool) $invoice->customer?->is_walk_in,
            'phone' => $invoice->customer?->is_walk_in ? null : $invoice->customer?->primaryPhone?->number,
            'first_line' => $invoice->items->sortBy('position')->first()?->description,
            'paid_at' => JobPresenter::iso($payment?->received_at),
            'paid_method' => $payment === null ? null : match (true) {
                $payment->card_last4 !== null => __('payments.refunds.to_card', ['last4' => $payment->card_last4]),
                default => $payment->method->label(),
            },
            'card_last4' => $payment?->card_last4,
        ];
    }

    /**
     * "Add customer" on a Walk-in invoice (owner's task of November 26): a customer picked from the database or typed
     * (name, phone, email, address); the invoice and its job move to them.
     */
    public function addCustomer(Request $request, Invoice $invoice, PaidCustomer $customers): RedirectResponse
    {
        Gate::authorize('update', $invoice->job);

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'property' => ['nullable', 'array'],
            'property.*' => ['nullable', 'string', 'max:255'],
        ]);

        $customers->applyToInvoice($invoice, array_filter($data, fn ($value) => $value !== null));
        app(AuditLogger::class)->record('invoice.customer_added', $invoice, ['number' => $invoice->number, 'customer_id' => $invoice->refresh()->customer_id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoices.add_customer.done')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function refundSetup(Invoice $invoice): array
    {
        $paid = $invoice->payments()->valid()->whereNull('refunded_payment_id')->where('amount', '>', 0)->orderByDesc('received_at')->get();
        $first = $paid->first();

        return [
            'left' => max(0, (int) $invoice->amount_paid),
            'taxes' => RefundAmounts::taxOptions($invoice),
            'square' => $paid->contains(fn (Payment $p) => $p->provider !== null),
            'method' => match ($first?->method->statsGroup()) {
                'cash' => 'cash',
                'e_transfer' => 'e_transfer',
                'card' => 'card',
                default => 'cash',
            },
            'card_last4' => $first?->card_last4,
        ];
    }

    public function create(Request $request, ServiceJob $job): Response
    {
        Gate::authorize('work', $job);

        // Due date from the customer's payment terms (or the company default), editable on the form.
        $terms = $job->customer()->withTrashed()->first()?->paymentTerms() ?? currentCompany()->default_payment_terms;
        $today = CarbonImmutable::now(currentCompany()->timezone)->startOfDay();
        $prefill = $request->boolean('diagnosis') ? [self::diagnosisLine()] : self::callbackLines($job);

        return Inertia::render('billing/form', [
            'kind' => 'invoice',
            'document' => null,
            ...BillingPresenter::reportForm($this->approvedEstimate($job), $job),
            'job' => BillingPresenter::job($job),
            'taxRates' => BillingPresenter::taxOptions(),
            'services' => BillingPresenter::serviceOptions($job->brand_id),
            'lineSetup' => BillingPresenter::lineSetup(),
            'today' => $today->format('Y-m-d'),
            'defaultDueOn' => $terms->dueOn($today)->format('Y-m-d'),
            'paymentTerms' => $terms->label(),
            // "Invoice diagnosis only" after the customer declined the repair: one line, the diagnostic fee.
            'prefillItems' => $prefill,
            // The approved estimate the new invoice starts from (UX-REDESIGN §21.2), and every estimate of the job
            // for "From estimate" in Add item.
            'fromEstimateId' => $prefill === null ? $this->approvedEstimate($job)?->id : null,
            'estimates' => self::jobEstimates($job),
            // A job may have several invoices (diagnosis, then repair), but a second one is shown before it is made.
            'existingInvoices' => $job->invoices()->get()
                ->reject(fn (Invoice $invoice) => $invoice->isVoid())
                ->map(fn (Invoice $invoice) => BillingPresenter::row($invoice))
                ->values(),
        ]);
    }

    /**
     * The estimate the customer approved (online or by "Mark approved") that is not invoiced yet, in the money
     * settings a new invoice gets (otherwise "Create invoice" on the estimate keeps its own).
     */
    private function approvedEstimate(ServiceJob $job): ?Estimate
    {
        $company = currentCompany();

        return $job->estimates()
            ->where('status', EstimateStatus::Approved->value)
            ->latest('id')
            ->get()
            ->first(fn (Estimate $estimate) => $estimate->currency === $company->currency
                && $estimate->prices_include_tax === (bool) $company->prices_include_tax);
    }

    /**
     * Estimates of the job with their lines, for "From estimate" when adding items.
     *
     * @return list<array<string, mixed>>
     */
    public static function jobEstimates(ServiceJob $job): array
    {
        return $job->estimates()
            ->where('status', '!=', EstimateStatus::Revised->value)
            ->orderBy('id')
            ->get()
            ->map(fn (Estimate $estimate) => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'status' => $estimate->status->value,
                'status_label' => $estimate->status->label(),
                'currency' => $estimate->currency,
                'discount_type' => $estimate->discount_type,
                'discount_value' => $estimate->discount_value,
                'notes' => $estimate->notes,
                // Optional lines the customer did not pick and other options are left out, as when the estimate is
                // invoiced.
                'items' => collect(BillingPresenter::document($estimate)['items'])
                    ->filter(fn (array $item) => ! $item['optional'] || $item['selected'])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
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

        $data = $request->document();
        $from = isset($data['estimate_id']) ? $job->estimates()->find($data['estimate_id']) : null;
        unset($data['estimate_id']);

        $invoice = $save->createInvoice($job, $data, $request->user(), $from);

        SavedToast::flash(route('invoices.show', $invoice), __('invoices.created', ['number' => $invoice->number]));

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
                // "Add customer" on a Walk-in invoice (owner's task of November 26).
                'addCustomer' => Gate::allows('update', $invoice->job) && (bool) $invoice->customer()->withTrashed()->value('is_walk_in'),
            ],
            // The refund window (owner's task of November 26): what is left to refund, the company's taxes ticked as
            // they were on the invoice, and how the paid money came in.
            'refund' => Gate::allows('refund', $invoice) ? self::refundSetup($invoice) : null,
            'paymentMethods' => PaymentMethod::manualOptions(currentCompany()),
            'today' => $this->today(),
            'online' => $this->online($invoice, $providers, $links),
            'delivery' => BillingPresenter::delivery($invoice),
            // Photos or screenshots the invoice was made from (assistant).
            'attachments' => $invoice->attachments()->get()->map(fn (InvoiceAttachment $a) => [
                'id' => $a->id,
                'url' => route('invoices.attachment', [$invoice, $a]),
            ])->values(),
            'sms' => $invoice->isVoid() ? null : app(MessagingPresenter::class)->forDocument($invoice),
            // "Send Google Review request?" asked after every invoice send (SPEC §8).
            'reviewPrompt' => $invoice->isVoid() ? null : app(ReviewRequests::class)->prompt($invoice),
            // From the "Send a review request?" notification: the request opens at once (sent only by Send).
            'openReview' => request()->boolean('review') && ! $invoice->isVoid(),
            // The customer signs when the work is done and the invoice is made, not earlier on the job.
            'signature' => [
                'job_id' => $invoice->service_job_id,
                'customer_name' => (string) $invoice->job->customer?->display_name,
                'data' => JobPresenter::signature($invoice->job),
                'can_sign' => ! $invoice->isVoid() && Gate::allows('work', $invoice->job),
            ],
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

    /**
     * A photo or screenshot attached to the invoice, to whoever can see the invoice.
     */
    public function attachment(Invoice $invoice, InvoiceAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $invoice);
        abort_unless($attachment->invoice_id === $invoice->id, 404);

        return PrivateMedia::response($attachment->path);
    }

    public function edit(Invoice $invoice): Response
    {
        Gate::authorize('update', $invoice);

        return Inertia::render('billing/form', [
            'kind' => 'invoice',
            'document' => BillingPresenter::document($invoice),
            ...BillingPresenter::reportForm($invoice, $invoice->job),
            'job' => BillingPresenter::job($invoice->job),
            'taxRates' => BillingPresenter::taxOptions($invoice->taxes),
            'services' => BillingPresenter::serviceOptions($invoice->brand_id),
            'lineSetup' => BillingPresenter::lineSetup(),
            'today' => $this->today(),
            'estimates' => self::jobEstimates($invoice->job),
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

        SavedToast::flash(route('invoices.show', $invoice), __('invoices.updated'));

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
