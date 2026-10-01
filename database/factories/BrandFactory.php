<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'primary_color' => fake()->hexColor(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->numerify('604-###-####'),
            'is_active' => true,
        ];
    }
}
