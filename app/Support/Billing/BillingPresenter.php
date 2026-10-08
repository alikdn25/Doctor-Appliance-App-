<?php

namespace App\Support\Billing;

use App\Actions\Billing\SendDocument;
use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LineKind;
use App\Enums\WarrantyUnit;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Support\Jobs\JobPresenter;

/**
 * Shapes estimates, invoices and payments for the React pages. Money stays in minor units, with its currency.
 */
class BillingPresenter
{
    /**
     * Short row for lists (job page, customer card, invoice list).
     *
     * @return array<string, mixed>
     */
    public static function row(Estimate|Invoice $document): array
    {
        $invoice = $document instanceof Invoice;

        return [
            'id' => $document->id,
            'kind' => $invoice ? 'invoice' : 'estimate',
            'number' => $document->number,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'issued_on' => $document->issued_on->toDateString(),
            'currency' => $document->currency,
            'total' => $document->total,
            'balance' => $invoice ? $document->balance : null,
            'overdue' => $invoice && $document->isOverdue(),
            'due_on' => $invoice ? $document->due_on?->toDateString() : null,
            'job_id' => $document->service_job_id,
            'customer' => $document->relationLoaded('customer') ? $document->customer?->display_name : null,
        ];
    }

    /**
     * The full document for its page and its edit form.
     *
     * @return array<string, mixed>
     */
    public static function document(Estimate|Invoice $document): array
    {
        $document->loadMissing(['items', 'job', 'customer.primaryPhone', 'customer.primaryEmail', 'property', 'brand', 'creator']);
        $invoice = $document instanceof Invoice;
        $costs = CostAccess::canEnterPrivate(auth()->user());

        $data = [
            ...self::row($document),
            'discount_type' => $document->discount_type,
            'discount_value' => $document->discount_value,
            'prices_include_tax' => $document->prices_include_tax,
            'subtotal' => $document->subtotal,
            'discount_total' => $document->discount_total,
            'tax_total' => $document->tax_total,
            'taxes' => $document->taxes,
            'notes' => $document->notes,
            'items' => $document->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'taxable' => $item->taxable,
                'tax_rate_ids' => $item->tax_rate_ids,
                'tax_names' => collect($document->taxes)->filter(fn ($tax) => $item->taxable && ($item->tax_rate_ids === null || in_array((int) $tax['tax_rate_id'], $item->tax_rate_ids, true)))->pluck('name')->values()->all(),
                'total' => $item->total,
                'optional' => $invoice ? false : $item->optional,
                'selected' => $invoice ? true : $item->selected,
                'kind' => $item->kind->value,
                'service_id' => $item->service_id,
                'part_number' => $item->part_number,
                'unit' => $item->unit,
                'bill_to_customer' => $item->bill_to_customer,
                'warranty_value' => $item->warranty_value,
                'warranty_unit' => $item->warranty_unit,
                'warranty_label' => $item->warrantyLabel(),
                'warranty_ends_on' => $invoice ? $item->warranty_ends_on?->toDateString() : null,
                'costs_editable' => $item->cost_owner_id === null || CostAccess::owns(auth()->user(), $item),
                // Purchase values are private to their author, including for Owners/Admins.
                'supplier' => CostAccess::owns(auth()->user(), $item) ? $item->supplier : null,
                'unit_cost' => CostAccess::owns(auth()->user(), $item) ? $item->unit_cost : null,
                'supplier_taxes' => CostAccess::owns(auth()->user(), $item) ? ($item->supplier_taxes ?? []) : [],
                'total_cost' => CostAccess::owns(auth()->user(), $item) ? $item->totalCost() : null,
                'private_difference' => CostAccess::owns(auth()->user(), $item) && $item->unit_cost !== null ? (int) round((float) $item->quantity * ($item->unit_price - $item->unit_cost)) : null,
            ])->values(),
            'costs_visible' => $costs,
            // An optional estimate line the customer has not picked is not bought, so its cost is left out.
            'cost_total' => $costs ? $document->items
                ->filter(fn ($item) => CostAccess::owns(auth()->user(), $item) && ($invoice || ! $item->optional || $item->selected))
                ->sum(fn ($item) => $item->totalCost()) : null,
            'job' => self::job($document->job),
            'customer' => [
                'id' => $document->customer->id,
                'display_name' => $document->customer->display_name,
                'phone' => $document->customer->primaryPhone?->number,
                'email' => $document->customer->primaryEmail?->email,
            ],
            'address' => $document->property?->fullAddress(),
            'brand' => $document->brand?->name,
            'created_by' => $document->creator?->name,
            'created_at' => JobPresenter::iso($document->created_at),
        ];

        if ($invoice) {
            $document->loadMissing(['payments.user', 'payments.voider', 'estimate', 'voider']);

            return [
                ...$data,
                'due_on' => $document->due_on?->toDateString(),
                'amount_paid' => $document->amount_paid,
                'credited_amount' => $document->credited_amount,
                'paid_at' => JobPresenter::iso($document->paid_at),
                'voided_at' => JobPresenter::iso($document->voided_at),
                'voided_by' => $document->voider?->name,
                'void_reason' => $document->void_reason,
                'estimate' => $document->estimate ? ['id' => $document->estimate->id, 'number' => $document->estimate->number] : null,
                'payments' => $document->payments->map(fn (Payment $p) => self::payment($p))->values(),
            ];
        }

        $document->loadMissing('invoice');

        return [
            ...$data,
            'valid_until' => $document->valid_until?->toDateString(),
            'expired' => $document->status->isOpen() && $document->isExpired(),
            'approved_at' => JobPresenter::iso($document->approved_at),
            'declined_at' => JobPresenter::iso($document->declined_at),
            'invoice' => $document->invoice ? ['id' => $document->invoice->id, 'number' => $document->invoice->number] : null,
            'deposit_type' => $document->deposit_type,
            'deposit_value' => $document->deposit_value,
            'deposit_amount' => $document->deposit_amount,
            'deposit_paid' => $document->depositPaid(),
            'online_approval' => $document->approvedOnline() ? [
                'signer_name' => $document->signer_name,
                'signature_type' => $document->signature_type,
                'signature' => DocumentPrint::signatureDataUri($document),
                'ip' => $document->approved_ip,
            ] : null,
            'decline_reason' => $document->decline_reason,
            'revision' => $document->revision,
            'revised_at' => JobPresenter::iso($document->revised_at),
            'revised_from' => $document->revised_from_id
                ? Estimate::query()->withTrashed()->whereKey($document->revised_from_id)->value('number')
                : null,
            'versions' => $document->revision > 1 || $document->revised_at !== null
                ? $document->versions()->map(fn (Estimate $v) => [
                    'id' => $v->id,
                    'number' => $v->number,
                    'revision' => $v->revision,
                    'status' => $v->status->value,
                    'status_label' => $v->status->label(),
                    'signer_name' => $v->signer_name,
                    'approved_at' => JobPresenter::iso($v->approved_at),
                    'revised_at' => JobPresenter::iso($v->revised_at),
                    'total' => $v->total,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'tip_amount' => $payment->tip_amount,
            'is_refund' => $payment->isRefund(),
            'currency' => $payment->currency,
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'reference' => $payment->reference,
            'note' => $payment->note,
            'received_at' => JobPresenter::iso($payment->received_at),
            'user' => $payment->user?->name,
            'provider' => $payment->provider,
            'voided_at' => JobPresenter::iso($payment->voided_at),
            'voided_by' => $payment->voider?->name,
            'void_reason' => $payment->void_reason,
            'refund_reason' => $payment->refund_reason,
            'processing_fee' => $payment->processing_fee,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function job(ServiceJob $job): array
    {
        $job->loadMissing(['customer', 'property', 'brand']);

        return [
            'id' => $job->id,
            'number' => $job->number,
            'customer' => $job->customer?->display_name,
            'address' => $job->property?->fullAddress(),
            'brand' => $job->brand?->name,
        ];
    }

    /**
     * Taxes to offer on the form. The active ones; plus those already on the document.
     *
     * @param  list<array{tax_rate_id: int|null, name: string, rate: string}>  $onDocument
     * @return list<array{id: int, name: string, rate: string, is_compound: bool, is_default: bool}>
     */
    public static function taxOptions(array $onDocument = []): array
    {
        $ids = array_filter(array_column($onDocument, 'tax_rate_id'));
        $saved = collect($onDocument)->keyBy('tax_rate_id');
        $positions = array_flip($ids);

        return TaxRate::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $ids))
            ->orderBy('is_compound')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TaxRate $rate) => [
                'id' => $rate->id,
                'name' => $saved[$rate->id]['name'] ?? $rate->name,
                'rate' => isset($saved[$rate->id]) ? (string) $saved[$rate->id]['rate'] : rtrim(rtrim((string) $rate->rate, '0'), '.'),
                'is_compound' => $saved[$rate->id]['compound'] ?? $rate->is_compound,
                'is_default' => $rate->is_default && $rate->is_active,
                'applies_to' => $rate->applies_to ?? array_column(LineKind::cases(), 'value'),
            ])
            ->sortBy(fn ($rate) => [(int) $rate['is_compound'], $positions[$rate['id']] ?? count($positions)])
            ->values()
            ->all();
    }

    /**
     * PDF, sending by email and the customer's online page.
     *
     * @return array<string, mixed>
     */
    public static function delivery(Estimate|Invoice $document): array
    {
        $document->loadMissing('customer.primaryEmail');
        $invoice = $document instanceof Invoice;

        return [
            'pdf_url' => route($invoice ? 'invoices.pdf' : 'estimates.pdf', $document),
            'send_url' => route($invoice ? 'invoices.send' : 'estimates.send', $document),
            'public_url' => $document->public_token ? route('documents.public', $document->public_token) : null,
            'can_send' => $invoice ? ! $document->isVoid() : $document->status !== EstimateStatus::Revised,
            'sent_at' => JobPresenter::iso($document->sent_at),
            'sent_to' => $document->sent_to,
            'viewed_at' => JobPresenter::iso($document->viewed_at),
            'email' => $document->customer?->primaryEmail?->email ?? '',
            'message' => SendDocument::defaultMessage($document),
        ];
    }

    /**
     * Active services of the price book, to fill estimate and invoice lines with one tap.
     * Prices are in the company currency; a document in another currency gets the description only.
     *
     * @return list<array{id: int, name: string, description: string|null, unit_price: int|null, currency: string, taxable: bool}>
     */
    public static function serviceOptions(int $brandId): array
    {
        $currency = currentCompany()->currency;

        return Service::query()
            ->availableForBrand($brandId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category,
                'description' => $service->description,
                'unit_price' => $service->unit_price,
                'currency' => $currency,
                'taxable' => $service->taxable,
                'kind' => $service->kind->value,
                'part_number' => $service->part_number,
                'unit' => $service->unit,
                'unit_cost' => CostAccess::owns(auth()->user(), $service) ? $service->unit_cost : null,
                'supplier' => CostAccess::owns(auth()->user(), $service) ? $service->supplier : null,
                'warranty_value' => $service->warranty_value,
                'warranty_unit' => $service->warranty_unit,
            ])
            ->values()
            ->all();
    }

    /**
     * What the line editor needs: private cost entry, warranty defaults, units and supplier taxes.
     *
     * @return array<string, mixed>
     */
    public static function lineSetup(): array
    {
        $company = currentCompany();

        return [
            'costs_visible' => CostAccess::canEnterPrivate(auth()->user()),
            'warranty' => [
                'labor' => ['value' => $company->warranty_labor_value, 'unit' => $company->warranty_labor_unit],
                'parts' => ['value' => $company->warranty_parts_value, 'unit' => $company->warranty_parts_unit],
                'parts_threshold' => $company->warranty_parts_threshold,
                'parts_above' => $company->warranty_parts_above_value === null ? null
                    : ['value' => $company->warranty_parts_above_value, 'unit' => $company->warranty_parts_above_unit ?? 'days'],
            ],
            'warranty_units' => WarrantyUnit::options(),
            'units' => array_map(fn (string $unit) => ['value' => $unit, 'label' => __("billing.units.{$unit}")], ['pcs', 'ft', 'm', 'lb', 'oz']),
            'supplier_taxes' => TaxRate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (TaxRate $rate) => [
                    'id' => $rate->id,
                    'name' => $rate->name,
                    'rate' => rtrim(rtrim((string) $rate->rate, '0'), '.'),
                    'recoverable' => $rate->is_recoverable,
                ])->values()->all(),
        ];
    }

    /**
     * Sums for an invoice list, by status (outstanding money first).
     *
     * @param  iterable<Invoice>  $invoices
     */
    public static function outstanding(iterable $invoices): int
    {
        $sum = 0;

        foreach ($invoices as $invoice) {
            if (in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true)) {
                $sum += $invoice->balance;
            }
        }

        return $sum;
    }
}
