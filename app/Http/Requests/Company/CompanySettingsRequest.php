<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', currentCompany());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::in(config('fieldservice.currencies'))],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'invoice_next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'estimate_prefix' => ['nullable', 'string', 'max:20'],
            'estimate_next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'business_hours' => ['required', 'array:'.implode(',', Company::WEEKDAYS)],
        ];

        foreach (Company::WEEKDAYS as $day) {
            $rules["business_hours.{$day}"] = ['required', 'array'];
            $rules["business_hours.{$day}.closed"] = ['required', 'boolean'];
            $rules["business_hours.{$day}.open"] = ["exclude_if:business_hours.{$day}.closed,true", 'required', 'date_format:H:i'];
            $rules["business_hours.{$day}.close"] = ["exclude_if:business_hours.{$day}.closed,true", 'required', 'date_format:H:i', "after:business_hours.{$day}.open"];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $data = $this->validated();

        foreach (Company::WEEKDAYS as $day) {
            $closed = (bool) $data['business_hours'][$day]['closed'];
            $data['business_hours'][$day] = [
                'closed' => $closed,
                'open' => $closed ? null : $data['business_hours'][$day]['open'],
                'close' => $closed ? null : $data['business_hours'][$day]['close'],
            ];
        }

        $data['invoice_prefix'] ??= '';
        $data['estimate_prefix'] ??= '';

        return $data;
    }
}
