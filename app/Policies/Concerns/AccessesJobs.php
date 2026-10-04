<?php

namespace App\Policies\Concerns;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Jobs are seen by the office (Owner, Office) within the brands they work for; each kind of office work
 * (scheduling, estimates, invoices) needs the matching permission the Owner gave. Anyone assigned to a visit of
 * a job (technicians, and Owners/Office members who go on calls) can see the job and work on it.
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

    /** The office sees every job of its brands, whatever its permissions. */
    protected function officeSeesJob(User $user, ServiceJob $job): bool
    {
        if (! $this->inCurrentCompany($job) || ! $this->isOffice($user)) {
            return false;
        }

        $brandIds = $user->limitedBrandIds();

        return $brandIds === [] || in_array($job->brand_id, $brandIds, true);
    }

    protected function managesJob(User $user, ServiceJob $job, OfficePermission $permission = OfficePermission::Schedule): bool
    {
        return $this->officeSeesJob($user, $job) && $user->canOffice($permission);
    }

    protected function assignedTo(User $user, ServiceJob $job): bool
    {
        return $this->inCurrentCompany($job) && $this->isFieldMember($user) && $job->isAssigned($user);
    }

    protected function seesJob(User $user, ServiceJob $job): bool
    {
        return $this->officeSeesJob($user, $job) || $this->assignedTo($user, $job);
    }

    /** Estimates or invoices of a job: the people on the job, or the office with that permission. */
    protected function billsJob(User $user, ServiceJob $job, OfficePermission $permission): bool
    {
        return $this->assignedTo($user, $job) || $this->managesJob($user, $job, $permission);
    }
}
