<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\ServiceJob;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers, properties and appliances are managed by the office (Owner, Admin).
 * Other team members can only view the customers, properties and appliances of jobs they can see.
 */
trait ManagesCustomers
{
    use ChecksTenant;

    protected function managesCustomers(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
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
