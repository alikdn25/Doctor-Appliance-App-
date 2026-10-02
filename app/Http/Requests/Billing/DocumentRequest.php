<?php

namespace App\Http\Requests\Billing;

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
            'items.*.unit_price' => ['required', 'numeric', self::moneyRule($this->currency(), negative: true)],
            'items.*.taxable' => ['boolean'],
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

        $data['items'] = array_map(fn (array $item) => [
            'description' => $item['description'],
            'quantity' => (string) $item['quantity'],
            'unit_price' => Currencies::toMinor($item['unit_price'], $this->currency()),
            'taxable' => (bool) ($item['taxable'] ?? true),
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
