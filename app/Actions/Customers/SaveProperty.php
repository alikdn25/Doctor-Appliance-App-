<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a customer's property. A customer always has exactly one primary property.
 * Must run in a tenant context.
 */
class SaveProperty
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Customer $customer, ?Property $property, array $attributes): Property
    {
        // There is no address without an address.
        if (array_key_exists('line1', $attributes) && blank($attributes['line1'])) {
            throw ValidationException::withMessages(['line1' => __('validation.required', ['attribute' => __('properties.fields.line1')])]);
        }

        return DB::transaction(function () use ($customer, $property, $attributes) {
            // A new address fills the customer's empty one (left by an old quick booking) instead of adding a second.
            $property ??= $customer->properties()->where(fn ($q) => $q->whereNull('line1')->orWhere('line1', ''))->orderBy('id')->first()
                ?? new Property;
            $property->fill($attributes);
            $property->customer()->associate($customer);

            if (! $customer->properties()->whereKeyNot($property->id ?? 0)->exists()) {
                $property->is_primary = true;
            }

            $property->save();

            if ($property->is_primary) {
                $customer->properties()->whereKeyNot($property->id)->update(['is_primary' => false]);
            } else {
                self::ensurePrimary($customer);
            }

            return $property;
        });
    }

    public static function ensurePrimary(Customer $customer): void
    {
        if (! $customer->properties()->where('is_primary', true)->exists()) {
            $customer->properties()->first()?->update(['is_primary' => true]);
        }
    }
}
