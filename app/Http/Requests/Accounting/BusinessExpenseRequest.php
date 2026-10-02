<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Billing\DocumentRequest;
use App\Models\BusinessExpense;
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
        $this->merge([
            'description' => is_string($this->description) ? trim($this->description) : $this->description,
            'new_category' => is_string($this->new_category) ? trim($this->new_category) : $this->new_category,
        ]);
    }

    public function rules(): array
    {
        $expense = $this->route('expense');
        $currency = $expense instanceof BusinessExpense ? $expense->currency : currentCompany()->currency;

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
            'tax_amount' => ['required', 'numeric', DocumentRequest::moneyRule($currency)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:15360'],
        ];
    }

    public function expenseData(): array
    {
        $data = $this->safe()->except(['receipt', 'new_category']);
        $expense = $this->route('expense');
        $currency = $expense instanceof BusinessExpense ? $expense->currency : currentCompany()->currency;

        $data['amount'] = Currencies::toMinor($data['amount'], $currency);
        $data['tax_amount'] = Currencies::toMinor($data['tax_amount'], $currency);

        return $data;
    }
}
