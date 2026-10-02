<?php

namespace App\Policies;

use App\Models\ServiceJob;
use App\Models\User;
use App\Policies\Concerns\AccessesJobs;

class ServiceJobPolicy
{
    use AccessesJobs;

    /**
     * The full job list (office).
     */
    public function viewAny(User $user): bool
    {
        return $this->isOffice($user);
    }

    /**
     * "My jobs": visits assigned to the user.
     */
    public function viewMine(User $user): bool
    {
        return $this->isFieldMember($user);
    }

    public function view(User $user, ServiceJob $job): bool
    {
        return $this->seesJob($user, $job);
    }

    public function create(User $user): bool
    {
        return $this->isOffice($user);
    }

    public function update(User $user, ServiceJob $job): bool
    {
        return $this->managesJob($user, $job);
    }

    public function delete(User $user, ServiceJob $job): bool
    {
        return $this->managesJob($user, $job);
    }

    /**
     * Field work on the job: tech notes, appliance details, adding an appliance.
     */
    public function work(User $user, ServiceJob $job): bool
    {
        return $this->seesJob($user, $job);
    }
}
