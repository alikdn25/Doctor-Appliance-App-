<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
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
                'total' => $item->total,
            ])->values(),
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
            'approved_at' => JobPresenter::iso($document->approved_at),
            'declined_at' => JobPresenter::iso($document->declined_at),
            'invoice' => $document->invoice ? ['id' => $document->invoice->id, 'number' => $document->invoice->number] : null,
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

        return TaxRate::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $ids))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TaxRate $rate) => [
                'id' => $rate->id,
                'name' => $rate->name,
                'rate' => rtrim(rtrim((string) $rate->rate, '0'), '.'),
                'is_compound' => $rate->is_compound,
                'is_default' => $rate->is_default && $rate->is_active,
            ])
            ->values()
            ->all();
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
