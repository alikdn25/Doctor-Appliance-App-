<?php

namespace App\Http\Requests\Customers;

use App\Enums\ApplianceType;
use App\Models\Appliance;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplianceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Appliance|null $appliance */
        $appliance = $this->route('appliance');
        /** @var Property|null $property */
        $property = $this->route('property');

        return $appliance
            ? $this->user()->can('update', $appliance)
            : $property !== null && $this->user()->can('update', $property);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['remove_rating_plate' => $this->boolean('remove_rating_plate')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ApplianceType::class)],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model_number' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'install_date' => ['nullable', 'date'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expires_on' => ['nullable', 'date'],
            'warranty_notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rating_plate' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'remove_rating_plate' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applianceAttributes(): array
    {
        return collect($this->validated())->except(['rating_plate', 'remove_rating_plate'])->all();
    }
}
