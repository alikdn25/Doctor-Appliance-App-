<?php

namespace App\Support\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;

/**
 * End dates of the warranties on a job's invoice lines. A warranty runs from the day the job was closed
 * (company time zone), or from the invoice date while the job is still open. Must run in a tenant context.
 */
class Warranties
{
    public static function start(Invoice $invoice): CarbonImmutable
    {
        $job = $invoice->job()->first();
        $closed = $job?->closed_at?->copy()->setTimezone(currentCompany()->timezone);

        return CarbonImmutable::parse(($closed ?? $invoice->issued_on)->format('Y-m-d'));
    }

    /**
     * Stores the end date of every line's warranty.
     */
    public static function stamp(Invoice $invoice): void
    {
        $start = self::start($invoice);

        $invoice->items()->get()->each(function (InvoiceItem $item) use ($start) {
            if (! $item->hasWarranty()) {
                return;
            }

            $ends = Warranty::endsOn($start, $item->warranty_value, $item->warranty_unit)?->toDateString();

            if ($item->warranty_ends_on?->toDateString() !== $ends) {
                $item->forceFill(['warranty_ends_on' => $ends])->save();
            }
        });
    }

    public static function stampJob(ServiceJob $job): void
    {
        $job->invoices()->get()->each(fn (Invoice $invoice) => self::stamp($invoice));
    }

    /**
     * The job's billed lines with their warranty on a given day (for warranty callbacks).
     *
     * @return list<array{invoice: string, invoice_id: int, item_id: int, description: string, kind: string, warranty: string, ends_on: string|null, active: bool, unit_price: int, quantity: string, taxable: bool}>
     */
    public static function onDate(ServiceJob $job, CarbonImmutable $day): array
    {
        $lines = [];

        foreach ($job->invoices()->where('status', '!=', 'void')->with('items')->get() as $invoice) {
            foreach ($invoice->items as $item) {
                if (! $item->bill_to_customer || ! $item->hasWarranty()) {
                    continue;
                }

                $lines[] = [
                    'invoice' => $invoice->number,
                    'invoice_id' => $invoice->id,
                    'item_id' => $item->id,
                    'description' => $item->description,
                    'kind' => $item->kind->value,
                    'warranty' => $item->warrantyLabel(),
                    'ends_on' => $item->warranty_ends_on?->toDateString(),
                    'active' => $item->warranty_ends_on !== null && $item->warranty_ends_on->toDateString() >= $day->toDateString(),
                    'unit_price' => $item->unit_price,
                    'quantity' => (string) $item->quantity,
                    'taxable' => $item->taxable,
                ];
            }
        }

        return $lines;
    }
}
