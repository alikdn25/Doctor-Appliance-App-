<?php

namespace App\Policies\Concerns;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\ServiceJob;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers, properties and appliances are read by the office and managed by the Owner or Office with Customers.
 * Other team members can only view the customers, properties and appliances of jobs they can see.
 */
trait ManagesCustomers
{
    use ChecksTenant;

    /** The office reads every customer. */
    protected function seesCustomers(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    /** Adding and changing customers, properties and appliances: Owner, or Office with the Customers permission. */
    protected function managesCustomers(User $user): bool
    {
        return $this->seesCustomers($user) && $user->canOffice(OfficePermission::Customers);
    }

    /**
     * @param  Closure(Builder<ServiceJob>): mixed  $constraint
     */
    protected function seesThroughJobs(User $user, Closure $constraint): bool
    {
        if (! $user->hasRole(UserRole::Technician)) {
            return false;
        }

        $query = ServiceJob::query()->visibleTo($user);
        $constraint($query);

        return $query->exists();
    }
}
