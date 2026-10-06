<?php

namespace App\Http\Requests\Jobs;

use App\Enums\ApplianceType;
use App\Enums\JobType;
use App\Enums\LeadSource;
use App\Enums\VisitType;
use App\Http\Requests\Customers\PropertyRequest;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a job (existing customer, or a new one typed in during the call) and editing its details.
 */
class JobRequest extends FormRequest
{
    /** Address parts the job form may change on the job's place. */
    private const ADDRESS_FIELDS = ['line1', 'unit', 'city', 'region', 'postal_code', 'country', 'google_place_id', 'latitude', 'longitude'];

    private ?Customer $resolvedCustomer = null;

    public function authorize(): bool
    {
        /** @var ServiceJob|null $job */
        $job = $this->route('job');

        return $job
            ? $this->user()->can('update', $job)
            : $this->user()->can('create', ServiceJob::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'new_customer_mode' => $this->creating() && $this->boolean('new_customer_mode'),
            'add_visit' => $this->creating() && $this->boolean('add_visit'),
            'quick_booking' => $this->creating() && $this->boolean('quick_booking'),
            'open_invoice' => $this->creating() && $this->boolean('open_invoice'),
            'appliance_ids' => array_values(array_filter((array) $this->input('appliance_ids', []), 'is_numeric')),
            'new_appliances' => array_values(array_filter((array) $this->input('new_appliances', []), 'is_array')),
        ]);

        if ($this->boolean('new_customer_mode')) {
            $property = (array) $this->input('new_customer.property', []);
            $property['country'] = strtoupper((string) ($property['country'] ?? currentCompany()->country));
            $this->merge(['new_customer' => [...(array) $this->input('new_customer', []), 'property' => $property]]);
        }
    }

    /**
     * Job types of the company's vertical; an existing job may keep a type outside it.
     *
     * @return list<string>
     */
    private function allowedJobTypes(): array
    {
        $types = array_map(fn (JobType $type) => $type->value, currentCompany()->vertical->jobTypes());
        $job = $this->route('job');

        if ($job instanceof ServiceJob) {
            $types[] = $job->job_type->value;
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'brand_id' => ['required', 'integer'],
            'job_type' => ['required', Rule::in($this->allowedJobTypes())],
            'lead_source' => ['nullable', Rule::enum(LeadSource::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'appliance_ids' => ['array', 'max:20'],
            'appliance_ids.*' => ['integer', 'distinct'],
            'new_appliances' => ['array', 'max:10'],
            'new_appliances.*.type' => ['required', Rule::enum(ApplianceType::class)],
            'new_appliances.*.manufacturer' => ['nullable', 'string', 'max:100'],
            'new_appliances.*.model_number' => ['nullable', 'string', 'max:100'],
            'new_appliances.*.serial_number' => ['nullable', 'string', 'max:100'],
            'visit_type' => ['sometimes', Rule::enum(VisitType::class)],
            'previous_job_id' => ['nullable', 'integer'],
            'bring_items' => ['array', 'max:50'],
            'bring_items.*.description' => ['required', 'string', 'max:255'],
            'bring_items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
        ];

        if (! $this->creating()) {
            return [
                ...$rules,
                'property_id' => ['required', 'integer'],
                // Name and main phone of the job's customer, corrected from the job form.
                ...(is_array($this->input('customer_edit')) ? [
                    'customer_edit' => ['array'],
                    'customer_edit.first_name' => ['nullable', 'required_without_all:customer_edit.last_name,customer_edit.company_name', 'string', 'max:100'],
                    'customer_edit.last_name' => ['nullable', 'string', 'max:100'],
                    'customer_edit.company_name' => ['nullable', 'string', 'max:255'],
                    'customer_edit.phone' => ['required', 'string', 'max:32'],
                ] : []),
                ...(is_array($this->input('address_edit')) ? [
                    'address_edit' => ['array'],
                    ...collect(PropertyRequest::addressRules('address_edit.', 'nullable'))
                        ->only(array_map(fn (string $f) => "address_edit.{$f}", self::ADDRESS_FIELDS))
                        ->all(),
                    'address_edit.line1' => ['nullable', 'required_with:address_edit.city', 'string', 'max:255'],
                    'address_edit.city' => ['nullable', 'required_with:address_edit.line1', 'string', 'max:100'],
                    'address_edit.country' => ['required', 'string', 'size:2'],
                ] : []),
            ];
        }

        $newCustomer = $this->boolean('new_customer_mode');

        return [
            ...$rules,
            'new_customer_mode' => ['boolean'],
            'quick_booking' => ['boolean'],
            'open_invoice' => ['boolean'],
            'customer_id' => $newCustomer ? ['nullable'] : ['required', 'integer'],
            'property_id' => $newCustomer ? ['nullable'] : ['required', 'integer'],
            ...($newCustomer ? [
                'new_customer.first_name' => ['nullable', 'required_without_all:new_customer.last_name,new_customer.company_name', 'string', 'max:100'],
                'new_customer.last_name' => ['nullable', 'string', 'max:100'],
                'new_customer.company_name' => ['nullable', 'string', 'max:255'],
                'new_customer.phone' => ['required', 'string', 'max:32'],
                'new_customer.email' => ['nullable', 'email', 'max:255'],
                'new_customer.notes' => ['nullable', 'string', 'max:10000'],
                ...PropertyRequest::addressRules('new_customer.property.'),
                ...($this->boolean('quick_booking') ? [
                    'new_customer.property.line1' => ['nullable', 'required_with:new_customer.property.city', 'string', 'max:255'],
                    'new_customer.property.city' => ['nullable', 'required_with:new_customer.property.line1', 'string', 'max:100'],
                ] : []),
            ] : []),
            'add_visit' => ['boolean'],
            ...VisitRequest::visitRules('visit.', 'required_if_accepted:add_visit'),
        ];
    }

    /**
     * @return array<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateBrand($validator);
            $this->validateCustomerAndProperty($validator);
            $this->validatePreviousJob($validator);

            $this->validateCustomerEdit($validator);

            if ($this->boolean('new_customer_mode')
                && ! PhoneNumber::isPossible((string) $this->input('new_customer.phone'))) {
                $validator->errors()->add('new_customer.phone', __('customers.invalid_phone'));
            }

            if ($this->boolean('add_visit')) {
                VisitRequest::validateAssignees($validator, (array) $this->input('visit.assignee_ids', []), 'visit.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'brand_id' => __('jobs.fields.brand'),
            'customer_id' => __('jobs.fields.customer'),
            'property_id' => __('jobs.fields.property'),
            'job_type' => __('jobs.fields.job_type'),
            'visit_type' => __('jobs.fields.visit_type'),
            'previous_job_id' => __('jobs.fields.previous_job_id'),
            'bring_items.*.description' => __('jobs.bring.item'),
            'customer_edit.first_name' => __('customers.fields.first_name'),
            'customer_edit.phone' => __('customers.fields.phone'),
            ...PropertyRequest::addressAttributes('address_edit.'),
            'new_customer.first_name' => __('customers.fields.first_name'),
            'new_customer.phone' => __('customers.fields.phone'),
            'new_customer.email' => __('customers.fields.email'),
            'new_appliances.*.type' => __('appliances.fields.type'),
            ...PropertyRequest::addressAttributes('new_customer.property.'),
            ...VisitRequest::visitAttributes('visit.'),
        ];
    }

    public function creating(): bool
    {
        return $this->route('job') === null;
    }

    public function customer(): ?Customer
    {
        return $this->resolvedCustomer;
    }

    /**
     * @return array<string, mixed>
     */
    public function jobAttributes(): array
    {
        return collect($this->validated())
            ->only(['brand_id', 'property_id', 'job_type', 'lead_source', 'description', 'notes', 'visit_type', 'previous_job_id'])
            ->when(
                fn ($attributes) => isset($attributes['visit_type']) && ! VisitType::from($attributes['visit_type'])->needsPreviousJob(),
                fn ($attributes) => $attributes->put('previous_job_id', null),
            )
            ->all();
    }

    /**
     * Parts and materials to bring (return visits); null when the form did not send the list.
     *
     * @return list<array{description: string, quantity: string}>|null
     */
    public function bringItems(): ?array
    {
        if (! $this->has('bring_items')) {
            return null;
        }

        return array_values(array_map(fn (array $item) => [
            'description' => trim($item['description']),
            'quantity' => (string) ($item['quantity'] ?? 1),
        ], $this->validated('bring_items', [])));
    }

    /**
     * @return list<int>
     */
    public function applianceIds(): array
    {
        return array_map('intval', $this->validated('appliance_ids', []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function newAppliances(): array
    {
        return array_values($this->validated('new_appliances', []));
    }

    /**
     * @return array{customer: array<string, mixed>, phones: list<array<string, mixed>>, emails: list<array<string, mixed>>, property: array<string, mixed>}|null
     */
    public function newCustomer(): ?array
    {
        if (! $this->boolean('new_customer_mode')) {
            return null;
        }

        $data = $this->validated('new_customer');

        // Quick booking has one name field: "Anna Kim" is stored as first name Anna, last name Kim.
        if ($this->boolean('quick_booking') && blank($data['last_name'] ?? null) && filled($data['first_name'] ?? null)) {
            $parts = preg_split('/\s+/u', trim($data['first_name']), 2);
            $data['first_name'] = $parts[0];
            $data['last_name'] = $parts[1] ?? null;
        }

        return [
            'customer' => [
                'type' => filled($data['company_name'] ?? null) && blank($data['first_name'] ?? null) && blank($data['last_name'] ?? null)
                    ? 'commercial'
                    : 'residential',
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'lead_source' => $this->validated('lead_source'),
            ],
            'phones' => [['label' => 'mobile', 'number' => $data['phone'], 'is_primary' => true]],
            'emails' => filled($data['email'] ?? null)
                ? [['label' => 'personal', 'email' => mb_strtolower($data['email']), 'is_primary' => true]]
                : [],
            'property' => [
                ...$data['property'],
                'line1' => $data['property']['line1'] ?? '',
                'city' => $data['property']['city'] ?? '',
                'is_primary' => true,
            ],
        ];
    }

    /**
     * Corrected name and main phone of the job's customer; null when the form did not send them.
     *
     * @return array{first_name: ?string, last_name: ?string, company_name: ?string, phone: string}|null
     */
    public function customerEdit(): ?array
    {
        if ($this->creating() || ! is_array($this->validated('customer_edit'))) {
            return null;
        }

        $data = $this->validated('customer_edit');

        return [
            'first_name' => filled($data['first_name'] ?? null) ? trim($data['first_name']) : null,
            'last_name' => filled($data['last_name'] ?? null) ? trim($data['last_name']) : null,
            'company_name' => filled($data['company_name'] ?? null) ? trim($data['company_name']) : null,
            'phone' => (string) $data['phone'],
        ];
    }

    /**
     * New address of the job's place; null when the form did not send one.
     *
     * @return array<string, mixed>|null
     */
    public function addressEdit(): ?array
    {
        if ($this->creating() || ! is_array($this->validated('address_edit'))) {
            return null;
        }

        $data = $this->validated('address_edit');
        $address = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $value = $data[$field] ?? null;
            $address[$field] = in_array($field, ['line1', 'city'], true) ? trim((string) $value) : (filled($value) ? $value : null);
        }
        $address['country'] = strtoupper((string) $address['country']);

        return $address;
    }

    /**
     * @return array{attributes: array<string, mixed>, assignee_ids: list<int>}|null
     */
    public function visit(): ?array
    {
        return $this->boolean('add_visit') ? VisitRequest::toVisit($this->validated('visit')) : null;
    }

    /**
     * A return visit or callback follows up an earlier job of the same customer.
     */
    private function validatePreviousJob(Validator $validator): void
    {
        $type = VisitType::tryFrom((string) $this->input('visit_type', VisitType::NewDiagnosis->value));

        if ($type === null || ! $type->needsPreviousJob()) {
            return;
        }

        if (! $this->filled('previous_job_id')) {
            $validator->errors()->add('previous_job_id', __('jobs.errors.previous_job_required'));

            return;
        }

        /** @var ServiceJob|null $job */
        $job = $this->route('job');
        $customerId = $job?->customer_id ?? $this->resolvedCustomer?->id;
        $previous = $customerId === null ? null : ServiceJob::query()
            ->where('customer_id', $customerId)
            ->whereKeyNot($job?->id ?? 0)
            ->find((int) $this->input('previous_job_id'));

        if ($previous === null) {
            $validator->errors()->add('previous_job_id', __('jobs.errors.invalid_previous_job'));
        }
    }

    private function validateCustomerEdit(Validator $validator): void
    {
        /** @var ServiceJob|null $job */
        $job = $this->route('job');

        if ($job === null || ! is_array($this->input('customer_edit'))) {
            return;
        }

        if ($job->customer === null || ! $this->user()->can('update', $job->customer)) {
            $validator->errors()->add('customer_edit', __('jobs.errors.invalid_customer'));

            return;
        }

        if (! PhoneNumber::isPossible((string) $this->input('customer_edit.phone'))) {
            $validator->errors()->add('customer_edit.phone', __('customers.invalid_phone'));
        }
    }

    private function validateBrand(Validator $validator): void
    {
        /** @var ServiceJob|null $job */
        $job = $this->route('job');
        $brandId = (int) $this->input('brand_id');
        $limited = $this->user()->limitedBrandIds();

        // An existing job may keep a brand that has since been deactivated.
        $brand = Brand::query()
            ->whereKey($brandId)
            ->when($job?->brand_id !== $brandId, fn ($q) => $q->where('is_active', true))
            ->first();

        if ($brand === null || ($limited !== [] && ! in_array($brand->id, $limited, true))) {
            $validator->errors()->add('brand_id', __('jobs.errors.invalid_brand'));
        }
    }

    private function validateCustomerAndProperty(Validator $validator): void
    {
        /** @var ServiceJob|null $job */
        $job = $this->route('job');

        if ($this->boolean('new_customer_mode')) {
            if ($this->input('appliance_ids', []) !== []) {
                $validator->errors()->add('appliance_ids', __('jobs.errors.invalid_appliance'));
            }

            return;
        }

        // Customer and Property are tenant-scoped: another company's IDs are simply not found.
        $customerId = $job?->customer_id ?? (int) $this->input('customer_id');
        $this->resolvedCustomer = Customer::query()->find($customerId);

        if ($this->resolvedCustomer === null) {
            $validator->errors()->add('customer_id', __('jobs.errors.invalid_customer'));

            return;
        }

        $propertyId = (int) $this->input('property_id');
        $property = Property::query()->where('customer_id', $customerId)->find($propertyId);

        if ($property === null) {
            $validator->errors()->add('property_id', __('jobs.errors.invalid_property'));

            return;
        }

        $ids = array_map('intval', (array) $this->input('appliance_ids', []));
        $found = Appliance::query()->where('property_id', $property->id)->whereKey($ids)->count();

        if ($found !== count(array_unique($ids))) {
            $validator->errors()->add('appliance_ids', __('jobs.errors.invalid_appliance'));
        }
    }
}
