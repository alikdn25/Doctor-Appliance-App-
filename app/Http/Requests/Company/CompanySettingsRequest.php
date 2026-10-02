<?php

namespace App\Http\Requests\Company;

use App\Enums\JobOutcome;
use App\Enums\PaymentTerms;
use App\Enums\WarrantyUnit;
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
            'online_tips' => ['boolean'],
            'default_payment_terms' => ['required', Rule::enum(PaymentTerms::class)],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'invoice_next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'estimate_prefix' => ['nullable', 'string', 'max:20'],
            'estimate_next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'business_hours' => ['required', 'array:'.implode(',', Company::WEEKDAYS)],
            'travel_buffer_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'estimate_valid_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'technicians_can_delete_jobs' => ['boolean'],
            'strict_arrival_reminder_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'diagnostic_service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('company_id', currentCompany()->id)],
            'warranty_labor_value' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'warranty_labor_unit' => ['sometimes', Rule::enum(WarrantyUnit::class)],
            'warranty_parts_value' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'warranty_parts_unit' => ['sometimes', Rule::enum(WarrantyUnit::class)],
            'warranty_parts_threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'warranty_parts_above_value' => ['nullable', 'integer', 'min:0', 'max:999'],
            'warranty_parts_above_unit' => ['nullable', Rule::enum(WarrantyUnit::class)],
            'warranty_terms' => ['nullable', 'string', 'max:5000'],
            'markup_parts' => ['sometimes', 'array', 'max:10'],
            'markup_parts.*.up_to' => ['nullable', 'numeric', 'min:0'],
            'markup_parts.*.multiplier' => ['required', 'numeric', 'min:1', 'max:20'],
            'markup_materials' => ['sometimes', 'array', 'max:10'],
            'markup_materials.*.up_to' => ['nullable', 'numeric', 'min:0'],
            'markup_materials.*.multiplier' => ['required', 'numeric', 'min:1', 'max:20'],
            'technicians_see_costs' => ['boolean'],
            'accepts_cash' => ['boolean'],
            'closure_reasons' => ['sometimes', 'array'],
            'closure_reasons.*' => ['array', 'max:30'],
            'closure_reasons.*.*' => ['nullable', 'string', 'max:100'],
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
        $data['online_tips'] = (bool) ($data['online_tips'] ?? false);
        $data['technicians_can_delete_jobs'] = (bool) ($data['technicians_can_delete_jobs'] ?? false);
        $data['technicians_see_costs'] = (bool) ($data['technicians_see_costs'] ?? false);
        $data['accepts_cash'] = (bool) ($data['accepts_cash'] ?? true);

        if (array_key_exists('warranty_parts_threshold', $data)) {
            $data['warranty_parts_threshold'] = filled($data['warranty_parts_threshold'])
                ? Currencies::toMinor((string) $data['warranty_parts_threshold'], $data['currency'] ?? currentCompany()->currency)
                : null;
        }

        // Markup tiers sorted by "up to" (in major units), the open-ended tier last.
        foreach (['markup_parts', 'markup_materials'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = collect($data[$key])
                    ->map(fn (array $tier) => [
                        'up_to' => filled($tier['up_to'] ?? null) ? (float) $tier['up_to'] : null,
                        'multiplier' => (float) $tier['multiplier'],
                    ])
                    ->sortBy(fn (array $tier) => $tier['up_to'] ?? PHP_FLOAT_MAX)
                    ->values()
                    ->all() ?: null;
            }
        }

        // One list per outcome; empty lines dropped; an empty list falls back to the defaults.
        if (array_key_exists('closure_reasons', $data)) {
            $data['closure_reasons'] = collect(JobOutcome::cases())
                ->filter(fn (JobOutcome $o) => $o->needsReason())
                ->mapWithKeys(fn (JobOutcome $o) => [$o->value => collect($data['closure_reasons'][$o->value] ?? [])
                    ->map(fn ($r) => trim((string) $r))->filter()->unique()->values()->all()])
                ->filter()
                ->all() ?: null;
        }

        return $data;
    }
}
