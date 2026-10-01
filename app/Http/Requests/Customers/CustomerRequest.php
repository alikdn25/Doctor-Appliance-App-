<?php

namespace App\Http\Requests\Customers;

use App\Enums\CustomerType;
use App\Enums\EmailLabel;
use App\Enums\LeadSource;
use App\Enums\PhoneLabel;
use App\Models\Customer;
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
            $property['country'] = strtoupper((string) ($property['country'] ?? 'CA'));
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
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['nullable', Rule::enum(LeadSource::class)],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:10000'],

            'phones' => ['array', 'max:10'],
            'phones.*.id' => ['nullable', 'integer'],
            'phones.*.label' => ['required', Rule::enum(PhoneLabel::class)],
            'phones.*.number' => ['required', 'string', 'max:32'],
            'phones.*.is_primary' => ['boolean'],

            'emails' => ['array', 'max:10'],
            'emails.*.id' => ['nullable', 'integer'],
            'emails.*.label' => ['required', Rule::enum(EmailLabel::class)],
            'emails.*.email' => ['required', 'email', 'max:255'],
            'emails.*.is_primary' => ['boolean'],
        ];

        if ($this->route('customer') === null) {
            $rules['add_property'] = ['boolean'];
            $rules += PropertyRequest::addressRules('property.', 'required_if_accepted:add_property');
        }

        return $rules;
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
                    if ($number !== '' && strlen(PhoneNumber::digits($number)) < 7) {
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
            ...PropertyRequest::addressAttributes('property.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerAttributes(): array
    {
        return collect($this->validated())
            ->only(['type', 'first_name', 'last_name', 'company_name', 'lead_source', 'tags', 'notes'])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function propertyAttributes(): ?array
    {
        if ($this->route('customer') !== null || ! $this->boolean('add_property')) {
            return null;
        }

        return [...$this->validated('property', []), 'is_primary' => true];
    }
}
