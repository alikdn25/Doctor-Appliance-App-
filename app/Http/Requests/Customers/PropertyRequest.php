<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Property|null $property */
        $property = $this->route('property');
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return $property
            ? $this->user()->can('update', $property)
            : $customer !== null && $this->user()->can('update', $customer);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_primary' => $this->boolean('is_primary'),
            'country' => strtoupper((string) $this->input('country', 'CA')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...self::addressRules(), 'is_primary' => ['boolean']];
    }

    /**
     * Rules for a property's fields, optionally nested under a prefix (e.g. "property.").
     *
     * @return array<string, array<mixed>>
     */
    public static function addressRules(string $prefix = '', string $required = 'required'): array
    {
        return [
            "{$prefix}label" => ['nullable', 'string', 'max:100'],
            "{$prefix}line1" => [$required, 'string', 'max:255'],
            "{$prefix}line2" => ['nullable', 'string', 'max:255'],
            "{$prefix}unit" => ['nullable', 'string', 'max:50'],
            "{$prefix}city" => [$required, 'string', 'max:100'],
            "{$prefix}region" => ['nullable', 'string', 'max:50'],
            "{$prefix}postal_code" => ['nullable', 'string', 'max:20'],
            "{$prefix}country" => [$required, 'string', 'size:2'],
            "{$prefix}access_notes" => ['nullable', 'string', 'max:2000'],
            "{$prefix}gate_code" => ['nullable', 'string', 'max:100'],
            "{$prefix}site_contact_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}site_contact_phone" => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::addressAttributes();
    }

    /**
     * @return array<string, string>
     */
    public static function addressAttributes(string $prefix = ''): array
    {
        return collect(['line1', 'city', 'country', 'site_contact_phone'])
            ->mapWithKeys(fn ($field) => ["{$prefix}{$field}" => __("properties.fields.{$field}")])
            ->all();
    }
}
