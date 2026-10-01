<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->randomElement(['GST', 'PST', 'HST']),
            'rate' => fake()->randomElement(['5.0000', '7.0000', '13.0000']),
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
