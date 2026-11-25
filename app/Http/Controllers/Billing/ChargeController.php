<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\SaveBillingDocument;
use App\Enums\LineKind;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Payments\PaymentProviderException;
use App\Payments\Square\SquareProvider;
use App\Support\Billing\ChargeLines;
use App\Support\Billing\DocumentTotals;
use App\Support\Billing\Money;
use App\Support\Locale\Currencies;
use App\Support\Payments\ChargeCheckout;
use App\Support\Payments\PaidCustomer;
use App\Support\Ui\SavedToast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Taking money on site (owner's task of November 21): the New charge screen ("+" → Charge reader), Take payment for
 * an invoice or a job (a quick invoice first when the job has none), and the charge itself: Charge reader (Square
 * Mobile Payments SDK in the Android app), Cash or E-transfer. Taxes are always worked out here.
 */
class ChargeController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('takeCharges');

        return Inertia::render('charges/new', [...$this->screen(), 'job' => null]);
    }

    /**
     * "Take payment" on a job: its open invoice, else a quick invoice first.
     */
    public function job(ServiceJob $job): Response|RedirectResponse
    {
        Gate::authorize('work', $job);

        $invoice = Invoice::query()->where('service_job_id', $job->id)->whereNull('voided_at')->where('balance', '>', 0)->latest('id')->first();
        if ($invoice !== null) {
            return to_route('invoices.take-payment', $invoice);
        }

        $job->loadMissing('customer');

        return Inertia::render('charges/new', [...$this->screen(), 'job' => [
            'id' => $job->id,
            'number' => $job->number,
            'customer' => $job->customer?->display_name,
            'description' => $job->description,
            'store_url' => route('jobs.quick-invoice', $job),
        ]]);
    }

    /**
     * The quick invoice of a job (lines; taxes by code), then the Take payment screen.
     */
    public function quickInvoice(Request $request, ServiceJob $job, SaveBillingDocument $save): RedirectResponse
    {
        Gate::authorize('work', $job);
        $data = $this->validateLines($request);
        $totals = ChargeCheckout::totals($data['lines'], $data['amounts_include_tax']);

        $invoice = $save->createInvoice($job, [
            'issued_on' => CarbonImmutable::now(currentCompany()->timezone)->toDateString(),
            'due_on' => null,
            'notes' => null,
            'tax_rate_ids' => $totals['tax_rate_ids'],
            'items' => array_map(fn (array $line) => [
                'description' => $line['description'],
                'quantity' => '1',
                'unit_price' => $line['unit_price'],
                'taxable' => $line['tax_rate_ids'] !== [],
                'tax_rate_ids' => $line['tax_rate_ids'],
                'kind' => LineKind::Service->value,
            ], $totals['lines']),
        ], $request->user());

        // Nothing to charge (no amount typed): the invoice is saved and opens for the prices (nothing is required).
        if ($totals['total'] <= 0) {
            SavedToast::flash(route('invoices.show', $invoice));

            return to_route('invoices.show', $invoice);
        }

        return to_route('invoices.take-payment', $invoice);
    }

    /**
     * Take payment for an invoice: the balance, which can be changed.
     */
    public function invoice(Invoice $invoice): Response|RedirectResponse
    {
        Gate::authorize('view', $invoice);

        $paid = $invoice->payments()->whereNull('voided_at')->where('amount', '>', 0)->latest('id')->first();
        if ($invoice->balance <= 0 && $paid !== null) {
            return to_route('payments.paid', $paid);
        }
        Gate::authorize('recordPayment', $invoice);
        $invoice->loadMissing('customer');

        return Inertia::render('payments/take', [
            ...$this->screen(),
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'customer' => $invoice->customer?->display_name,
                'balance' => $invoice->balance,
                'balance_input' => Money::input($invoice->balance, $invoice->currency),
                'balance_formatted' => Money::format($invoice->balance, $invoice->currency, currentCompany()->locale),
                'currency' => $invoice->currency,
                'url' => route('invoices.show', $invoice),
            ],
        ]);
    }

    /**
     * Taxes and the total of what is typed on New charge (code only).
     */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('takeCharges');
        $data = $this->validateLines($request);

        return response()->json($this->money(ChargeCheckout::totals($data['lines'], $data['amounts_include_tax'])));
    }

    /**
     * A phone typed on New charge that is already in the database: that customer's details.
     */
    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('takeCharges');
        $phone = (string) $request->validate(['phone' => ['required', 'string', 'max:40']])['phone'];

        return response()->json(['customer' => PaidCustomer::lookup($phone)]);
    }

    /**
     * Charge reader / Cash / E-transfer. Cash and E-transfer come back paid; Charge reader comes back with the
     * reference and the amount the phone sends to the reader.
     */
    public function store(Request $request, ChargeCheckout $checkout, SquareProvider $square): JsonResponse
    {
        Gate::authorize('takeCharges');
        $company = currentCompany();
        $data = $request->validate([
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, ChargeCheckout::METHODS))],
            'invoice_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'draft' => ['required_without:invoice_id', 'nullable', 'array'],
            ...$this->lineRules('draft.'),
            ...$this->customerRules('draft.customer.'),
        ]);
        $method = PaymentMethod::from($data['method']);

        $invoice = null;
        if (filled($data['invoice_id'] ?? null)) {
            $invoice = Invoice::query()->findOrFail($data['invoice_id']);
            Gate::authorize('recordPayment', $invoice);
        }

        if ($method === PaymentMethod::CardReader && ! $square->isConnected($company)) {
            return response()->json(['message' => __('payments.square.errors.not_connected')], 422);
        }

        $charge = $checkout->start(
            $request->user(),
            $invoice,
            $invoice === null ? $data['draft'] : null,
            $invoice !== null && isset($data['amount']) ? Currencies::toMinor($data['amount'], $invoice->currency) : null,
            $method,
        );

        if ($charge->isPaid()) {
            return response()->json(['status' => 'paid', 'paid_url' => route('payments.paid', $charge->payment_id)]);
        }

        return response()->json([
            'status' => 'pending',
            'reference' => $charge->reference,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            // Square: reference_id = the invoice number, note = what the job is about (no customer email or phone).
            'reference_id' => $charge->square_reference,
            'note' => ChargeCheckout::squareNote($charge),
            'complete_url' => route('charges.complete', $charge),
            'fail_url' => route('charges.fail', $charge),
        ]);
    }

    /**
     * The phone reports the Square payment the reader took; it is checked with Square before it is recorded.
     */
    public function complete(Request $request, Charge $charge, ChargeCheckout $checkout, SquareProvider $square): JsonResponse
    {
        Gate::authorize('takeCharges');
        abort_unless($charge->user_id === $request->user()->id && $charge->method === PaymentMethod::CardReader, 404);
        $paymentId = (string) $request->validate(['payment_id' => ['required', 'string', 'max:100']])['payment_id'];

        if (! $charge->isPaid()) {
            try {
                $charge = $checkout->complete($charge, $square->readerPayment($charge, $paymentId));
            } catch (PaymentProviderException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['status' => 'paid', 'paid_url' => route('payments.paid', $charge->payment_id)]);
    }

    /**
     * The reader said no (declined, cancelled, no reader): kept for the record; nothing is paid.
     */
    public function fail(Request $request, Charge $charge, ChargeCheckout $checkout, SquareProvider $square): JsonResponse
    {
        Gate::authorize('takeCharges');
        abort_unless($charge->user_id === $request->user()->id, 404);
        $text = ['nullable', 'string', 'max:500'];
        $data = $request->validate([
            'error' => $text,
            'code' => $text,
            'message' => ['nullable', 'string', 'max:1000'],
            'debug_code' => $text,
            'debug_message' => ['nullable', 'string', 'max:2000'],
            'details' => ['nullable', 'array', 'max:20'],
            'details.*' => ['nullable', 'string', 'max:1000'],
            'sdk_version' => $text,
            'device' => $text,
            'android' => $text,
            'app_version' => $text,
            'app_build' => $text,
            'application_id' => $text,
            'signing_sha256' => $text,
            'nfc' => $text,
            'environment' => $text,
            'sdk_location_id' => $text,
            'sdk_location_name' => $text,
            'raw' => ['nullable', 'string', 'max:2000'],
        ]);
        $checkout->fail($charge, (string) ($data['error'] ?? ''));
        $this->logCardFailure($charge, $data, $square);

        return response()->json(['status' => $charge->fresh()->status]);
    }

    /**
     * One line per failed card payment in storage/logs/card-payments.log: when, how much, which Square location,
     * the SDK's error, the SDK version, the phone and Android, the app build and environment on both sides.
     *
     * @param  array<string, mixed>  $data
     */
    private function logCardFailure(Charge $charge, array $data, SquareProvider $square): void
    {
        $company = currentCompany();
        $connection = $square->connection($company);
        $serverAppId = (string) config('services.square.application_id');

        Log::channel('card_payments')->warning('Card payment failed', [
            'time' => now()->toIso8601String(),
            'company_id' => $company->id,
            'user_id' => $charge->user_id,
            'charge' => $charge->reference,
            'amount' => Money::format($charge->amount, $charge->currency),
            'amount_minor' => $charge->amount,
            'currency' => $charge->currency,
            'location_id' => $connection?->location_id,
            'location_name' => $connection?->location_name,
            'sdk_location_id' => $data['sdk_location_id'] ?? null,
            'sdk_location_name' => $data['sdk_location_name'] ?? null,
            'error_code' => $data['code'] ?? null,
            'error_message' => $data['message'] ?? null,
            'debug_code' => $data['debug_code'] ?? null,
            'debug_message' => $data['debug_message'] ?? null,
            'details' => $data['details'] ?? [],
            'sdk_version' => $data['sdk_version'] ?? null,
            'device' => $data['device'] ?? null,
            'android' => $data['android'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'app_build' => $data['app_build'] ?? null,
            'app_signing_sha256' => $data['signing_sha256'] ?? null,
            'nfc' => $data['nfc'] ?? null,
            'app_environment' => $data['environment'] ?? null,
            'app_square_application_id' => $data['application_id'] ?? null,
            'server_environment' => config('services.square.environment'),
            'server_square_application_id' => $serverAppId,
            'application_ids_match' => filled($data['application_id'] ?? null) ? $data['application_id'] === $serverAppId : null,
            'missing_scopes' => $square->missingReaderScopes($company),
            'raw' => $data['raw'] ?? null,
        ]);
    }

    /**
     * What New charge and Take payment need: currency, taxes, quick amounts, the ways to pay.
     *
     * @return array<string, mixed>
     */
    private function screen(): array
    {
        $company = currentCompany();
        $square = app(SquareProvider::class);
        $connection = $square->connection($company);
        $taxes = ChargeLines::taxes();
        $quick = [];

        if ($company->diagnostic_fee !== null && $company->diagnostic_fee > 0) {
            $quick[] = ['label' => __('charges.diagnostic'), 'amount' => Money::input($company->diagnostic_fee, $company->currency)];
        }
        foreach (Service::query()->where('is_active', true)->whereNotNull('unit_price')->where('unit_price', '>', 0)->orderBy('sort_order')->orderBy('name')->limit(8)->get() as $service) {
            $quick[] = ['label' => $service->name, 'amount' => Money::input($service->unit_price, $company->currency)];
        }

        return [
            'currency' => $company->currency,
            'taxes' => array_map(fn (array $t) => ['id' => $t['tax_rate_id'], 'name' => $t['name'], 'rate' => DocumentTotals::rateLabel($t['rate'])], $taxes),
            'default_tax_ids' => ChargeLines::defaultIds($taxes),
            // "Price includes tax" is off on every new screen (never remembered): taxes are added on top.
            'price_includes_tax' => false,
            'quick_amounts' => array_values(collect($quick)->unique('label')->take(8)->all()),
            'methods' => [
                'cash' => $company->accepts_cash !== false,
                'e_transfer_label' => PaymentMethod::BankTransfer->label(),
            ],
            'reader' => [
                'connected' => $connection !== null && $connection->location_id !== null,
                'session_url' => route('payments.reader.session'),
                'settings_url' => route('company.settings.section', 'payments'),
            ],
            'urls' => [
                'preview' => route('charges.preview'),
                'lookup' => route('charges.lookup'),
                'store' => route('charges.store'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function money(array $totals): array
    {
        $locale = currentCompany()->locale;
        $format = fn (int $amount) => Money::format($amount, $totals['currency'], $locale);

        return [
            'subtotal' => $format($totals['subtotal']),
            'taxes' => array_map(fn (array $tax) => ['id' => $tax['tax_rate_id'], 'name' => $tax['name'], 'rate' => $tax['rate'], 'amount' => $format((int) $tax['amount'])], $totals['taxes']),
            'tax_total' => $format($totals['tax_total']),
            'total' => $format($totals['total']),
            'total_minor' => $totals['total'],
        ];
    }

    /**
     * @return array{lines: list<array{description: string|null, amount: string|null, tax_rate_ids?: list<int>}>, amounts_include_tax: bool}
     */
    private function validateLines(Request $request): array
    {
        $data = $request->validate($this->lineRules(''));

        return ['lines' => array_values($data['lines'] ?? []), 'amounts_include_tax' => (bool) ($data['amounts_include_tax'] ?? false)];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function lineRules(string $prefix): array
    {
        // Nothing is required (owner's task of November 25): a line without an amount is left out.
        return [
            $prefix.'lines' => ['nullable', 'array', 'max:20'],
            $prefix.'lines.*.description' => ['nullable', 'string', 'max:500'],
            $prefix.'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            $prefix.'lines.*.tax_rate_ids' => ['nullable', 'array', 'max:10'],
            $prefix.'lines.*.tax_rate_ids.*' => ['integer'],
            $prefix.'amounts_include_tax' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function customerRules(string $prefix): array
    {
        $address = fn (string $p) => collect(PaidCustomer::ADDRESS_FIELDS)->mapWithKeys(fn ($f) => [$prefix.$p.'.'.$f => ['nullable', 'string', 'max:255']])->all();

        return [
            $prefix.'phone' => ['nullable', 'string', 'max:40'],
            $prefix.'first_name' => ['nullable', 'string', 'max:255'],
            $prefix.'last_name' => ['nullable', 'string', 'max:255'],
            $prefix.'email' => ['nullable', 'email', 'max:255'],
            $prefix.'billing_same' => ['nullable', 'boolean'],
            ...$address('property'),
            ...$address('billing'),
        ];
    }
}
