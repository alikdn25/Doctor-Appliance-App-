<?php

namespace App\Actions\Billing;

use App\Enums\EstimateStatus;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Billing\DocumentTotals;
use App\Support\Locale\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates estimates and invoices of a job: line items, discount, taxes and totals.
 * Must run in a tenant context.
 *
 * $data: issued_on, valid_until|due_on, discount_type, discount_value, notes,
 *        tax_rate_ids (list<int>), items (list of description, quantity, unit_price in cents, taxable;
 *        estimates also optional, selected), deposit_type, deposit_value (estimates).
 */
class SaveBillingDocument
{
    public function __construct(
        private readonly NextDocumentNumber $numbers,
        private readonly SyncInvoice $syncInvoice,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createEstimate(ServiceJob $job, array $data, User $user): Estimate
    {
        return DB::transaction(function () use ($job, $data, $user) {
            $estimate = new Estimate;
            $this->attachToJob($estimate, $job, $user);
            $estimate->number = $this->numbers->estimate();
            $this->fill($estimate, $data, $this->taxSnapshot($data['tax_rate_ids'] ?? []));

            return $estimate;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createInvoice(ServiceJob $job, array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($job, $data, $user) {
            $invoice = new Invoice;
            $this->attachToJob($invoice, $job, $user);
            $invoice->number = $this->numbers->invoice();
            $this->fill($invoice, $data, $this->taxSnapshot($data['tax_rate_ids'] ?? []));
            $this->syncInvoice->handle($invoice, $user);

            return $invoice;
        });
    }

    /**
     * Turns an estimate into an invoice with the same lines, discount and taxes (rates as on the estimate).
     */
    public function convertEstimate(Estimate $estimate, User $user, string $issuedOn): Invoice
    {
        return DB::transaction(function () use ($estimate, $user, $issuedOn) {
            $estimate = Estimate::query()->lockForUpdate()->findOrFail($estimate->id);

            if (! $estimate->status->isOpen()) {
                throw ValidationException::withMessages(['estimate' => __('estimates.errors.already_invoiced')]);
            }

            $invoice = new Invoice;
            $this->attachToJob($invoice, $estimate->job, $user);
            $invoice->estimate_id = $estimate->id;
            $invoice->number = $this->numbers->invoice();
            // The invoice keeps the estimate's money settings, even if the company changed them since.
            $invoice->currency = $estimate->currency;
            $invoice->prices_include_tax = $estimate->prices_include_tax;

            $this->fill($invoice, [
                'issued_on' => $issuedOn,
                'due_on' => null,
                'discount_type' => $estimate->discount_type,
                'discount_value' => $estimate->discount_value,
                'notes' => $estimate->notes,
                // Optional lines the customer did not pick are left out.
                'items' => $estimate->items
                    ->filter(fn (EstimateItem $item) => $item->isIncluded())
                    ->map(fn (EstimateItem $item) => $item->only(['description', 'quantity', 'unit_price', 'taxable']))
                    ->values()
                    ->all(),
            ], $estimate->taxes);

            $estimate->status = EstimateStatus::Invoiced;
            $estimate->save();

            // A deposit paid on the estimate counts towards the invoice.
            Payment::query()
                ->where('estimate_id', $estimate->id)
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $invoice->id]);

            $this->syncInvoice->handle($invoice, $user);

            return $invoice;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Estimate|Invoice $document, array $data, User $user): void
    {
        DB::transaction(function () use ($document, $data, $user) {
            $this->fill($document, $data, $this->taxSnapshot($data['tax_rate_ids'] ?? [], $document->taxes));

            if ($document instanceof Invoice) {
                $this->syncInvoice->handle($document, $user);
            }
        });
    }

    private function attachToJob(Estimate|Invoice $document, ServiceJob $job, User $user): void
    {
        $company = currentCompany();
        $document->currency = $company->currency;
        $document->prices_include_tax = $company->prices_include_tax;
        $document->service_job_id = $job->id;
        $document->brand_id = $job->brand_id;
        $document->customer_id = $job->customer_id;
        $document->property_id = $job->property_id;
        $document->created_by = $user->id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{tax_rate_id: int|null, name: string, rate: string, compound?: bool}>  $taxes
     */
    private function fill(Estimate|Invoice $document, array $data, array $taxes): void
    {
        $estimate = $document instanceof Estimate;
        $items = array_values(array_map(fn (array $item) => [
            'description' => trim((string) $item['description']),
            'quantity' => (string) $item['quantity'],
            'unit_price' => (int) $item['unit_price'],
            'taxable' => (bool) $item['taxable'],
            ...($estimate ? [
                'optional' => (bool) ($item['optional'] ?? false),
                'selected' => ! ($item['optional'] ?? false) || (bool) ($item['selected'] ?? false),
            ] : []),
        ], $data['items']));

        $totals = DocumentTotals::calculate(
            array_map(fn (array $item) => [...$item, 'included' => $item['selected'] ?? true], $items),
            $data['discount_type'] ?? null,
            $data['discount_value'] ?? 0,
            $taxes,
            $document->prices_include_tax,
            Currencies::factor($document->currency),
        );

        if ($totals['total'] < 0) {
            throw ValidationException::withMessages(['items' => __('billing.errors.negative_total')]);
        }

        if ($document instanceof Invoice && $document->exists && $totals['total'] < $document->amount_paid) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.total_below_paid')]);
        }

        $document->fill([
            'issued_on' => $data['issued_on'],
            'discount_type' => ($data['discount_type'] ?? null) ?: null,
            'discount_value' => ($data['discount_type'] ?? null) ? ($data['discount_value'] ?? 0) : 0,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($document instanceof Invoice) {
            // Empty due date: the customer's payment terms (or the company default) from the invoice date.
            $document->due_on = ($data['due_on'] ?? null)
                ?: $this->dueOn($document, CarbonImmutable::parse($data['issued_on']))->toDateString();
        } else {
            $document->valid_until = $data['valid_until'] ?? null;
            $document->deposit_type = ($data['deposit_type'] ?? null) ?: null;
            $document->deposit_value = $document->deposit_type ? ($data['deposit_value'] ?? 0) : 0;
        }

        $this->applyTotals($document, $totals);
        $document->save();

        $document->items()->delete();
        foreach ($items as $position => $item) {
            $document->items()->create([...$item, 'position' => $position, 'total' => $totals['item_totals'][$position]]);
        }
        $document->unsetRelation('items');
    }

    /**
     * Recalculates an estimate's totals from its lines as they are (e.g. after the customer picked optional lines),
     * with the discount and the tax rates already on it.
     */
    public function recalculate(Estimate $estimate): void
    {
        $estimate->loadMissing('items');

        $totals = DocumentTotals::calculate(
            $estimate->items->map(fn (EstimateItem $item) => [
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'taxable' => $item->taxable,
                'included' => $item->isIncluded(),
            ])->values()->all(),
            $estimate->discount_type,
            $estimate->discount_value,
            $estimate->taxes,
            $estimate->prices_include_tax,
            Currencies::factor($estimate->currency),
        );

        $this->applyTotals($estimate, $totals);
        $estimate->save();
    }

    /**
     * @param  array{subtotal: int, discount_total: int, tax_total: int, total: int, taxes: list<array<string, mixed>>}  $totals
     */
    private function applyTotals(Estimate|Invoice $document, array $totals): void
    {
        $document->subtotal = $totals['subtotal'];
        $document->discount_total = $totals['discount_total'];
        $document->tax_total = $totals['tax_total'];
        $document->total = $totals['total'];
        $document->taxes = $totals['taxes'];

        if ($document instanceof Estimate) {
            $document->deposit_amount = $document->depositFor($document->total, Currencies::factor($document->currency));
        }
    }

    private function dueOn(Invoice $invoice, CarbonImmutable $issuedOn): CarbonImmutable
    {
        $customer = Customer::query()->withTrashed()->find($invoice->customer_id);

        return CarbonImmutable::instance($customer?->paymentTerms()->dueOn($issuedOn) ?? $issuedOn);
    }

    /**
     * Taxes to apply, as name + rate copied at the time. Taxes already on the document keep the rate they had.
     *
     * @param  list<int|string>  $taxRateIds
     * @param  list<array{tax_rate_id: int|null, name: string, rate: string, compound?: bool}>  $current
     * @return list<array{tax_rate_id: int|null, name: string, rate: string, compound: bool}>
     */
    private function taxSnapshot(array $taxRateIds, array $current = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $taxRateIds)));
        $kept = collect($current)->filter(fn (array $tax) => in_array((int) $tax['tax_rate_id'], $ids, true))->keyBy('tax_rate_id');
        // Compound taxes come last: they are charged on the amount plus the other taxes.
        $rates = TaxRate::query()->whereIn('id', $ids)->orderBy('is_compound')->orderBy('sort_order')->orderBy('name')->get();

        return $rates->map(fn (TaxRate $rate) => $kept->has($rate->id)
            ? ['tax_rate_id' => $rate->id, 'name' => $kept[$rate->id]['name'], 'rate' => (string) $kept[$rate->id]['rate'], 'compound' => (bool) ($kept[$rate->id]['compound'] ?? false)]
            : ['tax_rate_id' => $rate->id, 'name' => $rate->name, 'rate' => (string) $rate->rate, 'compound' => $rate->is_compound])
            ->values()
            ->all();
    }
}
