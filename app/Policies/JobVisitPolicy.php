<?php

namespace App\Policies;

use App\Models\JobVisit;
use App\Models\User;
use App\Policies\Concerns\AccessesJobs;

class JobVisitPolicy
{
    use AccessesJobs;

    /**
     * Scheduling: the office manages visits of its jobs.
     */
    public function update(User $user, JobVisit $visit): bool
    {
        return $this->inCurrentCompany($visit) && $this->managesJob($user, $visit->job);
    }

    public function delete(User $user, JobVisit $visit): bool
    {
        return $this->update($user, $visit);
    }

    /**
     * Status buttons (On my way, Start, Finish): people assigned to the visit, or the office.
     */
    public function work(User $user, JobVisit $visit): bool
    {
        return $this->inCurrentCompany($visit)
            && (($this->isFieldMember($user) && $visit->isAssigned($user)) || $this->managesJob($user, $visit->job));
    }
}
