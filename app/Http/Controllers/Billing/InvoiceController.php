<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CreateInvoicePaymentLink;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Billing\VoidBillingRecord;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Messaging\MessagingPresenter;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Payments\PaymentProviders;
use App\Services\AuditLogger;
use App\Support\Billing\BillingPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
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

    public function create(ServiceJob $job): Response
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
            'services' => BillingPresenter::serviceOptions(),
            'today' => $today->format('Y-m-d'),
            'defaultDueOn' => $terms->dueOn($today)->format('Y-m-d'),
            'paymentTerms' => $terms->label(),
        ]);
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
            ],
            'paymentMethods' => PaymentMethod::manualOptions(),
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
            'services' => BillingPresenter::serviceOptions(),
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
