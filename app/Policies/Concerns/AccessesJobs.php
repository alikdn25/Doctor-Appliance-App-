<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Jobs are managed by the office (Owner, Admin) within the brands they work for.
 * Anyone assigned to a visit of a job (technicians, and Owners/Admins who go on calls)
 * can see the job and work on it.
 */
trait AccessesJobs
{
    use ChecksTenant;

    protected function isOffice(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    protected function isFieldMember(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin, UserRole::Technician);
    }

    protected function managesJob(User $user, ServiceJob $job): bool
    {
        if (! $this->inCurrentCompany($job) || ! $this->isOffice($user)) {
            return false;
        }

        $brandIds = $user->limitedBrandIds();

        return $brandIds === [] || in_array($job->brand_id, $brandIds, true);
    }

    protected function seesJob(User $user, ServiceJob $job): bool
    {
        return $this->managesJob($user, $job)
            || ($this->inCurrentCompany($job) && $this->isFieldMember($user) && $job->isAssigned($user));
    }
}
