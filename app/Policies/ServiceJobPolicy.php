<?php

namespace App\Policies;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
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

    /**
     * The dispatch calendar (office).
     */
    /** Looking at the calendar (read-only without the Schedule permission). */
    public function viewCalendar(User $user): bool
    {
        return $this->isOffice($user);
    }

    public function dispatch(User $user): bool
    {
        return $this->isOffice($user) && $user->canOffice(OfficePermission::Schedule);
    }

    public function view(User $user, ServiceJob $job): bool
    {
        return $this->seesJob($user, $job);
    }

    public function create(User $user): bool
    {
        return $this->isOffice($user) && $user->canOffice(OfficePermission::Schedule);
    }

    public function update(User $user, ServiceJob $job): bool
    {
        return $this->managesJob($user, $job);
    }

    /**
     * Owners and Admins always (an Owner going on calls deletes as the Owner); technicians only on their own jobs
     * and only when the company allows it. Jobs with invoices or payments are never deleted (checked on delete).
     */
    public function delete(User $user, ServiceJob $job): bool
    {
        if ($this->managesJob($user, $job)) {
            return true;
        }

        return currentCompany()->technicians_can_delete_jobs
            && $user->hasRole(UserRole::Technician)
            && $this->inCurrentCompany($job)
            && $job->isAssigned($user);
    }

    /**
     * Deleted jobs (last 30 days) and restoring them: the office.
     */
    public function restore(User $user, ServiceJob $job): bool
    {
        return $this->managesJob($user, $job) && $job->trashed() && $job->isRestorable();
    }

    public function viewTrash(User $user): bool
    {
        return $this->isOffice($user) && $user->canOffice(OfficePermission::Schedule);
    }

    /** New estimates on the job. */
    public function estimate(User $user, ServiceJob $job): bool
    {
        return $this->billsJob($user, $job, OfficePermission::Estimates);
    }

    /** New invoices on the job. */
    public function invoice(User $user, ServiceJob $job): bool
    {
        return $this->billsJob($user, $job, OfficePermission::Invoices);
    }

    /**
     * Field work on the job: tech notes, appliance details, adding an appliance.
     */
    public function work(User $user, ServiceJob $job): bool
    {
        // Field work on the job (photos, notes, statuses, closing): the people on it, or the office that schedules.
        // A view-only Office member opens the job but changes nothing.
        return $this->assignedTo($user, $job) || $this->managesJob($user, $job);
    }
}
