<?php

namespace App\Http\Requests\Billing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Estimate or invoice form: dates, line items (prices in dollars), discount, taxes and notes.
 * Access is checked in the controller (it depends on the job or document in the route).
 */
class DocumentRequest extends FormRequest
{
    private const MONEY = 'regex:/^-?\d{1,7}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
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
                'integer',
                Rule::exists('tax_rates', 'id')->where('company_id', currentCompany()->id),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'items.*.unit_price' => ['required', 'numeric', self::MONEY],
            'items.*.taxable' => ['boolean'],
        ];
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

        $data['items'] = array_map(fn (array $item) => [
            'description' => $item['description'],
            'quantity' => (string) $item['quantity'],
            'unit_price' => self::cents($item['unit_price']),
            'taxable' => (bool) ($item['taxable'] ?? true),
        ], $data['items']);
        $data['tax_rate_ids'] ??= [];

        return $data;
    }

    public static function cents(string|int|float $dollars): int
    {
        return (int) round((float) $dollars * 100);
    }
}
