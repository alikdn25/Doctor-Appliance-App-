<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => CompanyStatus::Active,
            'timezone' => 'America/Vancouver',
            'currency' => 'CAD',
            'business_hours' => Company::defaultBusinessHours(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => CompanyStatus::Suspended]);
    }
}
