<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($customer, $property, $attributes) {
            $property ??= new Property;
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
