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
            // Test data is Canadian by default (most tests use BC addresses and 604 numbers);
            // use ->inUnitedStates() or ->state(['country' => ...]) for other countries.
            'country' => 'CA',
            'timezone' => 'America/Vancouver',
            'currency' => 'CAD',
            'locale' => 'en-CA',
            'business_hours' => Company::defaultBusinessHours(),
        ];
    }

    public function inUnitedStates(): static
    {
        return $this->state([
            'country' => 'US',
            'timezone' => 'America/Chicago',
            'currency' => 'USD',
            'locale' => 'en-US',
        ]);
    }

    public function handyman(): static
    {
        return $this->state(['vertical' => 'handyman']);
    }

    public function suspended(): static
    {
        return $this->state(['status' => CompanyStatus::Suspended]);
    }
}
