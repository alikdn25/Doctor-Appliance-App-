<?php

namespace App\Http\Requests\Company;

use App\Models\Brand;
use App\Rules\PostalCode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Brand|null $brand */
        $brand = $this->route('brand');

        return $brand
            ? $this->user()->can('update', $brand)
            : $this->user()->can('create', Brand::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'remove_logo' => $this->boolean('remove_logo'),
            'addresses' => collect($this->input('addresses', []))
                ->map(fn ($a) => [...$a, 'is_primary' => filter_var($a['is_primary'] ?? false, FILTER_VALIDATE_BOOL)])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hex = 'regex:/^#[0-9A-Fa-f]{6}$/';

        return [
            'name' => ['required', 'string', 'max:255'],
            'primary_color' => ['nullable', $hex],
            'secondary_color' => ['nullable', $hex],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'business_number' => ['nullable', 'string', 'max:50'],
            'invoice_footer' => ['nullable', 'string', 'max:5000'],
            'invoice_terms' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['boolean'],
            'addresses' => ['array', 'max:20'],
            'addresses.*.id' => ['nullable', 'integer'],
            'addresses.*.label' => ['nullable', 'string', 'max:100'],
            'addresses.*.line1' => ['required', 'string', 'max:255'],
            'addresses.*.line2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['required', 'string', 'max:100'],
            'addresses.*.region' => ['nullable', 'string', 'max:50'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20', new PostalCode],
            'addresses.*.country' => ['required', 'string', 'size:2'],
            'addresses.*.is_primary' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'addresses.*.line1' => __('brands.fields.line1'),
            'addresses.*.city' => __('brands.fields.city'),
            'addresses.*.country' => __('brands.fields.country'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function brandAttributes(): array
    {
        return collect($this->validated())->except(['logo', 'remove_logo', 'addresses'])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function addresses(): array
    {
        return array_values($this->validated('addresses', []));
    }
}
