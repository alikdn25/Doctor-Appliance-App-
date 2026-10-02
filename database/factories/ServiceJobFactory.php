<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Jobs are created in the company of their property. Use ->for($property).
 *
 * @extends Factory<ServiceJob>
 */
class ServiceJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'company_id' => fn (array $a) => Property::withoutCompanyScope()->find($a['property_id'])?->company_id,
            'customer_id' => fn (array $a) => Property::withoutCompanyScope()->find($a['property_id'])?->customer_id,
            'brand_id' => fn (array $a) => Brand::factory()->create(['company_id' => $a['company_id']])->id,
            'number' => fn (array $a) => Company::find($a['company_id'])?->job_next_number ?? 1001,
            'job_type' => JobType::Repair,
            'status' => JobStatus::New,
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Keep the company's job counter ahead of factory-made numbers.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ServiceJob $job) {
            Company::whereKey($job->company_id)
                ->update(['job_next_number' => DB::raw('greatest(job_next_number, '.($job->number + 1).')')]);
        });
    }

    public function status(JobStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Adds a visit (tomorrow 9–11 by default) assigned to the given users and moves the job to "scheduled".
     *
     * @param  list<User>|User  $users
     * @param  array<string, mixed>  $visit
     */
    public function withVisit(array|User $users = [], array $visit = []): static
    {
        $users = is_array($users) ? $users : [$users];

        return $this->state(fn () => ['status' => JobStatus::Scheduled])
            ->afterCreating(function (ServiceJob $job) use ($users, $visit) {
                JobVisit::factory()->for($job, 'job')->assignedTo($users)->create($visit);
            });
    }

    /**
     * Links appliances (of the job's property) to the job.
     *
     * @param  list<Appliance>  $appliances
     */
    public function withAppliances(array $appliances): static
    {
        return $this->afterCreating(function (ServiceJob $job) use ($appliances) {
            foreach ($appliances as $appliance) {
                $job->appliances()->attach($appliance->id, ['company_id' => $job->company_id]);
            }
        });
    }
}
