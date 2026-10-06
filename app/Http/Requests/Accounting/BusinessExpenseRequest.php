<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Billing\DocumentRequest;
use App\Models\BusinessExpense;
use App\Models\TaxRate;
use App\Support\Billing\MoneyInput;
use App\Support\Locale\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof BusinessExpense
            ? $this->user()->can('update', $expense)
            : $this->user()->can('create', BusinessExpense::class);
    }

    protected function prepareForValidation(): void
    {
        $this->replace(MoneyInput::cleanPaths($this->input(), ['amount', 'tax_amount', 'tax_amounts.*']));
        $this->merge([
            'description' => is_string($this->description) ? trim($this->description) : $this->description,
            'new_category' => is_string($this->new_category) ? trim($this->new_category) : $this->new_category,
        ]);
    }

    public function rules(): array
    {
        $expense = $this->route('expense');
        $currency = $expense instanceof BusinessExpense ? $expense->currency : currentCompany()->currency;

        $keptTaxes = $expense instanceof BusinessExpense ? array_column($expense->taxes ?? [], 'tax_rate_id') : [];

        return [
            'category_id' => [
                'nullable', 'required_without:new_category', 'integer',
                Rule::exists('business_expense_categories', 'id')->where('company_id', currentCompany()->id)
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $expense?->category_id ?? 0)),
            ],
            'new_category' => ['nullable', 'required_without:category_id', 'string', 'max:80'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'merchant' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', DocumentRequest::moneyRule($currency)],
            'tax_amount' => ['required_unless:use_named_taxes,1', 'nullable', 'numeric', DocumentRequest::moneyRule($currency)],
            'use_named_taxes' => ['boolean'],
            'tax_rate_ids' => ['array'],
            'tax_rate_ids.*' => ['integer', 'distinct', Rule::exists('tax_rates', 'id')->where('company_id', currentCompany()->id)
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keptTaxes))],
            'tax_amounts' => ['array'],
            'tax_amounts.*' => ['required', 'numeric', DocumentRequest::moneyRule($currency)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:15360'],
        ];
    }

    public function expenseData(): array
    {
        $data = $this->safe()->except(['receipt', 'new_category', 'use_named_taxes', 'tax_rate_ids', 'tax_amounts']);
        $expense = $this->route('expense');
        $currency = $expense instanceof BusinessExpense ? $expense->currency : currentCompany()->currency;

        $data['amount'] = Currencies::toMinor($data['amount'], $currency);
        if ($this->boolean('use_named_taxes')) {
            $kept = collect($expense?->taxes ?? [])->keyBy('tax_rate_id');
            $rates = TaxRate::query()->whereIn('id', $this->validated('tax_rate_ids', []))
                ->orderBy('is_compound')->orderBy('sort_order')->orderBy('name')->get();
            $amounts = $this->validated('tax_amounts', []);
            $data['taxes'] = $rates->map(fn (TaxRate $rate) => [
                'tax_rate_id' => $rate->id,
                'name' => $kept[$rate->id]['name'] ?? $rate->name,
                'rate' => $kept[$rate->id]['rate'] ?? (string) $rate->rate,
                'compound' => $kept[$rate->id]['compound'] ?? $rate->is_compound,
                'amount' => Currencies::toMinor($amounts[$rate->id] ?? '0', $currency),
            ])->all();
            $data['tax_amount'] = array_sum(array_column($data['taxes'], 'amount'));
        } else {
            $data['taxes'] = null;
            $data['tax_amount'] = Currencies::toMinor($data['tax_amount'], $currency);
        }

        return $data;
    }
}
