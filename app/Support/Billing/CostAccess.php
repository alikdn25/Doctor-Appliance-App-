<?php

namespace App\Support\Billing;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Who sees costs, suppliers and profit: Owners and Admins; technicians only when the company allows it.
 */
class CostAccess
{
    public static function canSee(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(UserRole::Owner, UserRole::Admin)
            || ($user->hasRole(UserRole::Technician) && currentCompany()->technicians_see_costs);
    }
}
