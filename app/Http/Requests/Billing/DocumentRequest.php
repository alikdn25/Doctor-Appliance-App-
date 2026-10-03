<?php

namespace App\Http\Requests\Billing;

use App\Enums\LineKind;
use App\Enums\WarrantyUnit;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Support\Billing\CostAccess;
use App\Support\Locale\Currencies;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Estimate or invoice form: dates, line items (prices in major units of the document currency, e.g. dollars),
 * discount, taxes and notes. Estimates also have optional lines and a deposit asked on approval.
 * Access is checked in the controller (it depends on the job or document in the route).
 */
class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $document = $this->route('invoice') ?? $this->route('estimate') ?? $this->route('job');
        $brandId = $document instanceof Model ? (int) $document->brand_id : 0;
        // Preserve references on existing documents even if catalogue availability changed later.
        $existingServices = $document instanceof Invoice || $document instanceof Estimate
            ? $document->items()->whereNotNull('service_id')->pluck('service_id')->all()
            : [];

        $existingTaxes = $document instanceof Invoice || $document instanceof Estimate ? array_column($document->taxes, 'tax_rate_id') : [];

        return [
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'discount_type' => ['nullable', Rule::in(['amount', 'percent'])],
            'discount_value' => [
                'nullable', 'numeric', 'min:0',
                $this->input('discount_type') === 'percent' ? 'max:100' : 'max:9999999',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'tax_rate_ids' => ['array'],
            'tax_rate_ids.*' => [
                'integer', 'distinct',
                Rule::exists('tax_rates', 'id')->where('company_id', currentCompany()->id)
                    ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $existingTaxes)),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'items.*.unit_price' => ['required', 'numeric', self::moneyRule($this->currency(), negative: true)],
            'items.*.taxable' => ['boolean'],
            'items.*.tax_rate_ids' => ['nullable', 'array'],
            'items.*.tax_rate_ids.*' => ['integer', Rule::in(is_array($this->input('tax_rate_ids')) ? $this->input('tax_rate_ids') : [])],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.kind' => ['nullable', Rule::enum(LineKind::class)],
            'items.*.service_id' => [
                'nullable', 'integer',
                Rule::exists('services', 'id')->where('company_id', currentCompany()->id)
                    ->where(fn ($q) => $q->whereJsonLength('brand_ids', 0)->orWhereJsonContains('brand_ids', $brandId)->orWhereIn('id', $existingServices)),
            ],
            'items.*.part_number' => ['nullable', 'string', 'max:100'],
            'items.*.supplier' => ['nullable', 'string', 'max:150'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_cost' => ['nullable', 'numeric', self::moneyRule($this->currency())],
            'items.*.supplier_taxes' => ['nullable', 'array'],
            'items.*.supplier_taxes.*.tax_rate_id' => ['required', 'integer', Rule::exists('tax_rates', 'id')->where('company_id', currentCompany()->id)],
            'items.*.supplier_taxes.*.amount' => ['required', 'numeric', self::moneyRule($this->currency())],
            'items.*.bill_to_customer' => ['boolean'],
            'items.*.warranty_value' => ['nullable', 'integer', 'min:0', 'max:999'],
            'items.*.warranty_unit' => ['nullable', Rule::enum(WarrantyUnit::class)],
            ...($this->isEstimate() ? [
                'items.*.optional' => ['boolean'],
                'items.*.selected' => ['boolean'],
                'deposit_type' => ['nullable', Rule::in(['amount', 'percent'])],
                'deposit_value' => [
                    'nullable', 'required_with:deposit_type', 'numeric', 'min:0',
                    ...($this->input('deposit_type') === 'percent' ? ['max:100'] : [self::moneyRule($this->currency())]),
                ],
            ] : []),
        ];
    }

    public function isEstimate(): bool
    {
        return $this->routeIs('estimates.*');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items.*.description' => __('billing.fields.description'),
            'items.*.quantity' => __('billing.fields.quantity'),
            'items.*.unit_price' => __('billing.fields.unit_price'),
            'discount_value' => __('billing.fields.discount'),
            'valid_until' => __('estimates.fields.valid_until'),
            'due_on' => __('invoices.fields.due_on'),
            'deposit_value' => __('estimates.fields.deposit'),
            'issued_on' => __('billing.fields.issued_on'),
        ];
    }

    /**
     * Validated data with prices in cents, as SaveBillingDocument expects it.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $data = $this->validated();

        $currency = $this->currency();
        $costs = CostAccess::canEnterPrivate($this->user());
        $rates = TaxRate::query()->get()->keyBy('id');

        $data['items'] = array_map(fn (array $item) => [
            'id' => isset($item['id']) ? (int) $item['id'] : null,
            'description' => $item['description'],
            'quantity' => (string) $item['quantity'],
            'unit_price' => Currencies::toMinor($item['unit_price'], $currency),
            'taxable' => (bool) ($item['taxable'] ?? true),
            ...(array_key_exists('tax_rate_ids', $item) ? ['tax_rate_ids' => $item['tax_rate_ids'] === null ? null : array_map('intval', $item['tax_rate_ids'])] : []),
            'kind' => $item['kind'] ?? LineKind::Service->value,
            'service_id' => $item['service_id'] ?? null,
            'part_number' => $item['part_number'] ?? null,
            'unit' => $item['unit'] ?? null,
            'bill_to_customer' => (bool) ($item['bill_to_customer'] ?? true),
            'warranty_value' => isset($item['warranty_value']) ? (int) $item['warranty_value'] : null,
            'warranty_unit' => $item['warranty_unit'] ?? null,
            // Without access to costs these are left out, and the saved line keeps what it had.
            ...($costs ? [
                'supplier' => $item['supplier'] ?? null,
                'unit_cost' => isset($item['unit_cost']) && $item['unit_cost'] !== '' ? Currencies::toMinor($item['unit_cost'], $currency) : null,
                'supplier_taxes' => array_values(array_map(fn (array $tax) => [
                    'tax_rate_id' => (int) $tax['tax_rate_id'],
                    'name' => $rates[(int) $tax['tax_rate_id']]?->name,
                    'amount' => Currencies::toMinor($tax['amount'], $currency),
                    'recoverable' => (bool) ($rates[(int) $tax['tax_rate_id']]?->is_recoverable ?? true),
                ], array_filter($item['supplier_taxes'] ?? [], fn (array $tax) => (float) $tax['amount'] > 0))) ?: null,
            ] : []),
            ...($this->isEstimate() ? [
                'optional' => (bool) ($item['optional'] ?? false),
                'selected' => ! ($item['optional'] ?? false) || (bool) ($item['selected'] ?? false),
            ] : []),
        ], $data['items']);
        $data['tax_rate_ids'] ??= [];

        return $data;
    }

    /**
     * An amount typed in major units with at most the currency's decimals ("12.50" USD, "1200" JPY).
     */
    public static function moneyRule(string $currency, bool $negative = false): string
    {
        $decimals = Currencies::decimals($currency);
        $fraction = $decimals > 0 ? '(\.\d{1,'.$decimals.'})?' : '';

        return 'regex:/^'.($negative ? '-?' : '').'\d{1,9}'.$fraction.'$/';
    }

    /**
     * Currency of the document being edited, or of the company for a new one.
     */
    public function currency(): string
    {
        $document = $this->route('invoice') ?? $this->route('estimate');

        return $document instanceof Model ? $document->currency : currentCompany()->currency;
    }
}
