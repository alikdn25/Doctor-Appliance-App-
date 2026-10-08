<?php

namespace App\Http\Requests\Company;

use App\Enums\LineKind;
use App\Models\TaxRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TaxRate|null $taxRate */
        $taxRate = $this->route('taxRate');

        return $taxRate
            ? $this->user()->can('update', $taxRate)
            : $this->user()->can('create', TaxRate::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_compound' => $this->boolean('is_compound'),
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->boolean('is_active'),
            'is_recoverable' => $this->boolean('is_recoverable', true),
        ]);

        // Every line type ticked is the same as no restriction.
        if (is_array($this->input('applies_to')) && count(array_unique($this->input('applies_to'))) >= count(LineKind::cases())) {
            $this->merge(['applies_to' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'is_compound' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'is_recoverable' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'applies_to' => ['nullable', 'array'],
            'applies_to.*' => ['distinct', Rule::enum(LineKind::class)],
        ];
    }
}
