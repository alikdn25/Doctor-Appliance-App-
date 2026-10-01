<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => CustomerType::Residential,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'lead_source' => fake()->randomElement(LeadSource::cases()),
            'tags' => [],
        ];
    }

    public function commercial(?string $name = null): static
    {
        return $this->state(fn () => [
            'type' => CustomerType::Commercial,
            'company_name' => $name ?? fake()->company(),
        ]);
    }

    /**
     * Adds a primary phone (rows are written in the customer's company).
     */
    public function withPhone(string $number = '604-555-0100'): static
    {
        return $this->afterCreating(function (Customer $customer) use ($number) {
            $phone = new CustomerPhone(['number' => $number, 'is_primary' => true]);
            $phone->company_id = $customer->company_id;
            $phone->customer_id = $customer->id;
            $phone->save();
        });
    }

    public function withEmail(?string $email = null): static
    {
        return $this->afterCreating(function (Customer $customer) use ($email) {
            $row = new CustomerEmail(['email' => $email ?? fake()->unique()->safeEmail(), 'is_primary' => true]);
            $row->company_id = $customer->company_id;
            $row->customer_id = $customer->id;
            $row->save();
        });
    }
}
