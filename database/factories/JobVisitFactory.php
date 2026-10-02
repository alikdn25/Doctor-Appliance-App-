<?php

namespace Database\Factories;

use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobVisit>
 */
class JobVisitFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDay()->setTime(9, 0);

        return [
            'service_job_id' => ServiceJob::factory(),
            'company_id' => fn (array $a) => ServiceJob::withoutCompanyScope()->find($a['service_job_id'])?->company_id,
            'scheduled_start' => $start,
            'scheduled_end' => $start->addHours(2),
            'estimated_duration_minutes' => 60,
            'status' => VisitStatus::Scheduled,
        ];
    }

    /**
     * @param  list<User>|User  $users
     */
    public function assignedTo(array|User $users): static
    {
        $users = is_array($users) ? $users : [$users];

        return $this->afterCreating(function (JobVisit $visit) use ($users) {
            foreach ($users as $user) {
                $visit->assignees()->attach($user->id, ['company_id' => $visit->company_id]);
            }
        });
    }
}
