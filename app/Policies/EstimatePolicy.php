<?php

namespace App\Policies;

use App\Models\Estimate;
use App\Models\User;
use App\Policies\Concerns\AccessesJobs;

/**
 * Estimates follow their job: the office of the job's brand and the people assigned to the job
 * (technicians create estimates on site).
 */
class EstimatePolicy
{
    use AccessesJobs;

    public function view(User $user, Estimate $estimate): bool
    {
        return $this->inCurrentCompany($estimate) && $this->seesJob($user, $estimate->job);
    }

    public function update(User $user, Estimate $estimate): bool
    {
        return $this->view($user, $estimate) && $estimate->status->isOpen();
    }

    public function delete(User $user, Estimate $estimate): bool
    {
        return $this->update($user, $estimate);
    }
}
