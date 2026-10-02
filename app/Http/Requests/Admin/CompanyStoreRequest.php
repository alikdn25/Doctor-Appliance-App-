<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubscriptionStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyStoreRequest extends FormRequest
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
            // Empty: detect from the Owner's browser on their first visit.
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['required', Rule::in(config('fieldservice.currencies'))],
            'plan' => ['nullable', 'string', 'max:50'],
            'subscription_status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
        ];
    }
}
