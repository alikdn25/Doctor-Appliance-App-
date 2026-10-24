<?php

namespace App\Http\Requests\Customers;

use App\Enums\CustomerType;
use App\Enums\EmailLabel;
use App\Enums\LeadSource;
use App\Enums\PaymentTerms;
use App\Enums\PhoneLabel;
use App\Models\Customer;
use App\Rules\PostalCode;
use App\Support\NameAvatar;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return $customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

    protected function prepareForValidation(): void
    {
        $primary = fn (array $rows) => collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => [...$row, 'is_primary' => filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOL)])
            ->values()
            ->all();

        $tags = $this->input('tags', []);
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        $this->merge([
            'phones' => $primary((array) $this->input('phones', [])),
            'emails' => $primary((array) $this->input('emails', [])),
            'tags' => collect(is_array($tags) ? $tags : [])
                ->map(fn ($tag) => trim((string) $tag))
                ->filter()
                ->unique(fn ($tag) => mb_strtolower($tag))
                ->values()
                ->all(),
            'add_property' => $this->boolean('add_property'),
        ]);

        if ($this->boolean('add_property')) {
            $property = (array) $this->input('property', []);
            $property['country'] = strtoupper((string) ($property['country'] ?? currentCompany()->country));
            $this->merge(['property' => $property]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'first_name' => ['nullable', 'required_without_all:last_name,company_name', 'string', 'max:100'],
            'avatar_style' => ['sometimes', Rule::in(NameAvatar::STYLES)],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['nullable', Rule::enum(LeadSource::class)],
            // Empty = the company's default payment terms.
            'payment_terms' => ['nullable', Rule::enum(PaymentTerms::class)],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:10000'],

            'phones' => ['array', 'max:10'],
            'phones.*.id' => ['nullable', 'integer'],
            'phones.*.label' => ['required', Rule::enum(PhoneLabel::class)],
            'phones.*.number' => ['required', 'string', 'max:32'],
            'phones.*.contact_name' => ['nullable', 'string', 'max:100'],
            'phones.*.is_primary' => ['boolean'],

            'emails' => ['array', 'max:10'],
            'emails.*.id' => ['nullable', 'integer'],
            'emails.*.label' => ['required', Rule::enum(EmailLabel::class)],
            'emails.*.email' => ['required', 'email', 'max:255'],
            'emails.*.is_primary' => ['boolean'],
        ];

        // The service address, on create and on edit (the main address is edited in place).
        $rules['add_property'] = ['boolean'];
        $rules['property.id'] = ['nullable', 'integer'];
        $rules += PropertyRequest::addressRules('property.', 'required_if_accepted:add_property');

        // Billing address of the payer; sent empty = the same as the primary address.
        if ($this->has('billing')) {
            $rules += self::billingRules('billing.');
        }

        return $rules;
    }

    /**
     * Billing address rules: optional, but a street needs a city.
     *
     * @return array<string, array<mixed>>
     */
    public static function billingRules(string $prefix): array
    {
        return [
            rtrim($prefix, '.') => ['nullable', 'array'],
            "{$prefix}line1" => ['nullable', 'string', 'max:255'],
            "{$prefix}unit" => ['nullable', 'string', 'max:50'],
            "{$prefix}city" => ['nullable', "required_with:{$prefix}line1", 'string', 'max:100'],
            "{$prefix}region" => ['nullable', 'string', 'max:50'],
            "{$prefix}postal_code" => ['nullable', 'string', 'max:20', new PostalCode],
            "{$prefix}country" => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * Customer columns for a validated billing address; an empty street clears it (same as the primary address).
     *
     * @param  array<string, mixed>|null  $billing
     * @return array<string, string|null>
     */
    public static function billingColumns(?array $billing): array
    {
        $filled = filled($billing['line1'] ?? null);
        $columns = [];
        foreach (Customer::BILLING_FIELDS as $field) {
            $value = $filled ? trim((string) ($billing[$field] ?? '')) : '';
            $columns["billing_{$field}"] = $value === '' ? null : $value;
        }
        if ($filled) {
            $columns['billing_country'] = strtoupper($columns['billing_country'] ?? currentCompany()->country);
        }

        return $columns;
    }

    /**
     * @return array<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('phones', []) as $i => $phone) {
                    $number = (string) ($phone['number'] ?? '');
                    if ($number !== '' && ! PhoneNumber::isPossible($number)) {
                        $validator->errors()->add("phones.{$i}.number", __('customers.invalid_phone'));
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => __('customers.fields.first_name'),
            'last_name' => __('customers.fields.last_name'),
            'company_name' => __('customers.fields.company_name'),
            'phones.*.number' => __('customers.fields.phone'),
            'emails.*.email' => __('customers.fields.email'),
            'billing.line1' => __('customers.fields.billing_address'),
            'billing.city' => __('properties.fields.city'),
            ...PropertyRequest::addressAttributes('property.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerAttributes(): array
    {
        return collect($this->validated())
            ->only(['type', 'first_name', 'last_name', 'company_name', 'lead_source', 'payment_terms', 'tags', 'notes', 'avatar_style'])
            ->merge($this->has('billing') ? self::billingColumns($this->validated('billing')) : [])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function propertyAttributes(): ?array
    {
        if (! $this->boolean('add_property')) {
            return null;
        }

        $attributes = collect($this->validated('property', []))->except('id')->all();

        return $this->route('customer') === null ? [...$attributes, 'is_primary' => true] : $attributes;
    }

    /**
     * The address being edited on the customer form (null = a new one).
     */
    public function propertyId(): ?int
    {
        $id = $this->boolean('add_property') ? $this->validated('property.id') : null;

        return $id === null ? null : (int) $id;
    }
}
