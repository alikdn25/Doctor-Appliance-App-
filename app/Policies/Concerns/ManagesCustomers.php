<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Customers, properties and appliances are managed by the office (Owner, Admin).
 * Technicians get access to customers on their own jobs once jobs exist.
 */
trait ManagesCustomers
{
    use ChecksTenant;

    protected function managesCustomers(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }
}
