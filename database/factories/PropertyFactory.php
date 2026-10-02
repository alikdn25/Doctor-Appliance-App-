<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'company_id' => fn (array $attributes) => Customer::withoutCompanyScope()->find($attributes['customer_id'])?->company_id,
            'line1' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Vancouver', 'Surrey', 'Burnaby', 'Richmond', 'Coquitlam']),
            'region' => 'BC',
            'postal_code' => strtoupper(fake()->bothify('V#? #?#')),
            'country' => 'CA',
            'is_primary' => true,
        ];
    }
}
