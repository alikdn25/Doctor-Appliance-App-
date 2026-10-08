<?php

namespace App\Support\Billing;

use App\Models\Brand;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Support\Locale\AddressFormatter;
use App\Support\PhoneNumber;
use App\Support\PrivateMedia;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use IntlDateFormatter;
use Throwable;

/**
 * Everything a customer sees of an estimate or invoice (PDF, email, online page), already formatted in the
 * company's regional format and currency, and branded with the document's brand. Must run in a tenant context.
 */
class DocumentPrint
{
    /**
     * @return array<string, mixed>
     */
    public static function data(Estimate|Invoice $document, bool $embedLogo = false): array
    {
        $estimate = $document instanceof Estimate;

        $document->loadMissing(['items', 'customer.primaryPhone', 'customer.primaryEmail', 'property', 'brand.addresses', 'job']);
        $company = currentCompany();
        $invoice = $document instanceof Invoice;
        $money = fn (int $minor) => Money::format($minor, $document->currency, $company->locale);
        $brand = $document->brand;

        return [
            'kind' => $invoice ? 'invoice' : 'estimate',
            'title' => __($invoice ? 'documents.invoice' : 'documents.estimate'),
            'number' => $document->number,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'currency' => $document->currency,
            'issued_on' => self::date($document->issued_on),
            'due_on' => $invoice ? self::date($document->due_on) : null,
            'valid_until' => $invoice ? null : self::date($document->valid_until),
            'job_number' => $document->job?->number,
            'brand' => self::brand($brand, $company->country, $embedLogo) + ['fallback_name' => $company->name],
            'customer' => [
                'name' => $document->customer?->display_name,
                'phone' => PhoneNumber::display($document->customer?->primaryPhone?->number, $company->country) ?: null,
                'email' => $document->customer?->primaryEmail?->email,
                'address' => $document->property?->fullAddress(),
            ],
            // Internal lines (cost only) are never shown to the customer.
            'items' => $document->items->filter(fn ($item) => $item->bill_to_customer)->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.'),
                'unit_price' => $money($item->unit_price),
                'total' => $money($item->total),
                'taxable' => $item->taxable,
                'tax_rate_ids' => $item->tax_rate_ids,
                'tax_names' => collect($document->taxes)->filter(fn ($tax) => $item->taxable && ($item->tax_rate_ids === null || in_array((int) $tax['tax_rate_id'], $item->tax_rate_ids, true)))->pluck('name')->values()->all(),
                'optional' => $estimate && $item->optional,
                'included' => ! $estimate || $item->isIncluded(),
                'unit' => $item->unit,
                'part_number' => $item->part_number,
                'warranty' => $item->hasWarranty() && $item->warranty_value ? $item->warrantyLabel() : null,
                'warranty_until' => ! $estimate && $item->hasWarranty() ? self::date($item->warranty_ends_on) : null,
            ])->values()->all(),
            'warranty_terms' => $company->warranty_terms,
            'subtotal' => $money($document->subtotal),
            'discount' => $document->discount_total > 0 ? $money(-$document->discount_total) : null,
            'taxes' => collect($document->taxes)->map(fn (array $tax) => [
                'label' => __($document->prices_include_tax ? 'billing.includes_tax' : 'billing.tax_line', ['name' => $tax['name'], 'rate' => $tax['rate']]),
                'amount' => $money((int) $tax['amount']),
            ])->values()->all(),
            'prices_include_tax' => $document->prices_include_tax,
            'total' => $money($document->total),
            'amount_paid' => $invoice && $document->amount_paid !== 0 ? $money($document->amount_paid) : null,
            'balance' => $invoice ? $money($document->balance) : null,
            'balance_minor' => $invoice ? $document->balance : null,
            'notes' => $document->notes,
            'terms' => $brand?->invoice_terms,
            'footer' => $brand?->invoice_footer,
            'approval' => $estimate ? self::approval($document, $money) : null,
        ];
    }

    /**
     * Online approval of an estimate: who signed and when, the signature, the deposit, or why it was declined.
     *
     * The drawn signature is embedded (small PNG), so the PDF and the online page need no extra request.
     *
     * @param  callable(int): string  $money
     * @return array<string, mixed>
     */
    private static function approval(Estimate $estimate, callable $money): array
    {
        $paid = $estimate->deposit_amount > 0 ? $estimate->depositPaid() : 0;

        return [
            'approved_at' => self::dateTime($estimate->approved_at),
            'declined_at' => self::dateTime($estimate->declined_at),
            'online' => $estimate->approvedOnline(),
            'signer_name' => $estimate->signer_name,
            'signature_type' => $estimate->signature_type,
            'signature' => self::signatureDataUri($estimate),
            'approved_ip' => $estimate->approved_ip,
            'decline_reason' => $estimate->decline_reason,
            'expired' => $estimate->isExpired(),
            'deposit' => $estimate->deposit_amount > 0 ? $money($estimate->deposit_amount) : null,
            'deposit_percent' => $estimate->deposit_type === 'percent' ? rtrim(rtrim((string) $estimate->deposit_value, '0'), '.') : null,
            'deposit_paid' => $paid > 0 ? $money($paid) : null,
            'deposit_due' => $estimate->deposit_amount > 0 ? $money(max(0, $estimate->deposit_amount - $paid)) : null,
            'deposit_due_minor' => $estimate->deposit_amount > 0 ? max(0, $estimate->deposit_amount - $paid) : 0,
        ];
    }

    /**
     * Date and time in the company's time zone and regional format, e.g. "Oct 6, 2026, 3:15 PM".
     */
    public static function dateTime(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $company = currentCompany();
        $formatter = new IntlDateFormatter(str_replace('-', '_', $company->locale), IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, $company->timezone);

        return (string) $formatter->format($at);
    }

    public static function signatureDataUri(Estimate $estimate): ?string
    {
        if ($estimate->signature_path === null) {
            return null;
        }

        try {
            $contents = PrivateMedia::disk()->get($estimate->signature_path);

            return $contents === null ? null : 'data:image/png;base64,'.base64_encode($contents);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(?Brand $brand, string $country, bool $embedLogo = false): array
    {
        $address = $brand?->addresses->firstWhere('is_primary', true) ?? $brand?->addresses->first();

        return [
            'name' => $brand?->name,
            'color' => $brand?->primary_color ?: '#0f172a',
            'logo' => $embedLogo ? self::logoDataUri($brand) : $brand?->logo_url,
            'address' => $address
                ? AddressFormatter::oneLine(
                    AddressFormatter::street($address->line1, $address->line2, $address->city, $address->country),
                    $address->city, $address->region, AddressFormatter::postal($address->postal_code), $address->country,
                )
                : null,
            'phone' => $brand?->phone ? PhoneNumber::display($brand->phone, $country) : null,
            'email' => $brand?->email,
            'website' => $brand?->website,
            'tax_number' => $brand?->tax_number,
            'business_number' => $brand?->business_number,
        ];
    }

    /**
     * A date in the company's regional format, e.g. "Oct 6, 2026" (en-US) or "6 Oct 2026" (en-GB).
     */
    public static function date(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $formatter = new IntlDateFormatter(str_replace('-', '_', currentCompany()->locale), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');

        return (string) $formatter->format($date->copy()->setTimezone('UTC')->startOfDay()->setTime(12, 0));
    }

    /**
     * dompdf cannot fetch remote images reliably: the logo is embedded as a data URI.
     */
    private static function logoDataUri(?Brand $brand): ?string
    {
        if ($brand?->logo_path === null) {
            return null;
        }

        try {
            $disk = Storage::disk(config('fieldservice.media_disk'));
            $contents = $disk->get($brand->logo_path);
            $mime = $disk->mimeType($brand->logo_path) ?: 'image/png';

            return $contents === null ? null : 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable) {
            return null;
        }
    }
}
