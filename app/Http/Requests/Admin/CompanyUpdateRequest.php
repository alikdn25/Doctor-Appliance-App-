<?php

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
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
            'plan' => ['nullable', 'string', 'max:50'],
            'subscription_status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
        ];
    }
}
