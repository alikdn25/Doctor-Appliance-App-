<?php

namespace Database\Factories;

use App\Enums\ApplianceType;
use App\Models\Appliance;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appliance>
 */
class ApplianceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'company_id' => fn (array $attributes) => Property::withoutCompanyScope()->find($attributes['property_id'])?->company_id,
            'type' => fake()->randomElement(ApplianceType::cases()),
            'manufacturer' => fake()->randomElement(['Whirlpool', 'Samsung', 'LG', 'Bosch', 'Frigidaire']),
            'model_number' => strtoupper(fake()->bothify('??###??#')),
            'serial_number' => strtoupper(fake()->bothify('##?#####')),
        ];
    }
}
