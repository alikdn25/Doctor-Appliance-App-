<?php

namespace App\Http\Requests\Company;

use App\Enums\PaymentTerms;
use App\Models\Company;
use App\Payments\PaymentProviders;
use App\Support\Locale\Countries;
use App\Support\Locale\Currencies;
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
            'country' => ['required', Rule::in(Countries::codes())],
            'currency' => ['required', Rule::in(Currencies::codes())],
            'locale' => ['required', Rule::in(array_column(Countries::localeOptions(), 'value'))],
            'prices_include_tax' => ['boolean'],
            'default_payment_terms' => ['required', Rule::enum(PaymentTerms::class)],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'invoice_next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'estimate_prefix' => ['nullable', 'string', 'max:20'],
            'estimate_next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'business_hours' => ['required', 'array:'.implode(',', Company::WEEKDAYS)],
            'travel_buffer_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            // Only a provider the company has connected (and that serves its country) can be picked.
            'payment_provider' => ['nullable', Rule::in(array_column(app(PaymentProviders::class)->options(currentCompany()), 'value'))],
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

        // A time zone saved by the Owner is final; browser detection no longer applies.
        $data['timezone_pending'] = false;
        $data['invoice_prefix'] ??= '';
        $data['estimate_prefix'] ??= '';
        $data['payment_provider'] ??= null;
        $data['prices_include_tax'] = (bool) ($data['prices_include_tax'] ?? false);

        return $data;
    }
}
