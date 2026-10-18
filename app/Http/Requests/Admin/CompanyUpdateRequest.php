<?php

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdmin();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(CompanyStatus::class)],
            // A plan name set before the list existed stays valid until it is changed.
            'plan' => ['nullable', 'string', Rule::in([...array_column(SubscriptionPlan::cases(), 'value'), $this->route('company')?->plan])],
            'subscription_status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
        ];
    }
}
