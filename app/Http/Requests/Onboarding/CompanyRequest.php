<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\Vertical;
use App\Support\Locale\Countries;
use App\Support\Locale\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->isSuperAdmin() && $this->user()->memberships()->doesntExist();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'country' => ['required', Rule::in(Countries::codes())],
            'vertical' => ['required', Rule::enum(Vertical::class)],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::in(Currencies::codes())],
            'locale' => ['required', Rule::in(array_column(Countries::localeOptions(), 'value'))],
        ];
    }
}
