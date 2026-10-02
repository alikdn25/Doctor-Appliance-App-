<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\ServiceJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A invoice of a job with no lines yet. Prefer SaveBillingDocument in tests that need lines and totals.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_job_id' => ServiceJob::factory(),
            'company_id' => fn (array $a) => ServiceJob::withoutCompanyScope()->find($a['service_job_id'])?->company_id,
            'brand_id' => fn (array $a) => ServiceJob::withoutCompanyScope()->find($a['service_job_id'])?->brand_id,
            'customer_id' => fn (array $a) => ServiceJob::withoutCompanyScope()->find($a['service_job_id'])?->customer_id,
            'property_id' => fn (array $a) => ServiceJob::withoutCompanyScope()->find($a['service_job_id'])?->property_id,
            'number' => 'INV-'.fake()->unique()->numberBetween(1, 999999),
            'issued_on' => now()->toDateString(),
        ];
    }
}
