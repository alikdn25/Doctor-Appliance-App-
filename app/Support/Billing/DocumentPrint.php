<?php

namespace App\Support\Billing;

use App\Models\Brand;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Support\Locale\AddressFormatter;
use App\Support\PhoneNumber;
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
            'items' => $document->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.'),
                'unit_price' => $money($item->unit_price),
                'total' => $money($item->total),
                'taxable' => $item->taxable,
            ])->values()->all(),
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
        ];
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
                    collect([$address->line1, $address->line2])->filter()->implode(', '),
                    $address->city, $address->region, $address->postal_code, $address->country,
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
