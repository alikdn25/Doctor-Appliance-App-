<?php

namespace App\Support\Billing;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who sees costs, suppliers and profit: Owners and Admins; technicians only when the company allows it.
 */
class CostAccess
{
    public static function canEnterPrivate(?User $user): bool
    {
        return $user !== null && $user->hasRole(UserRole::Owner, UserRole::Admin, UserRole::Technician);
    }

    public static function owns(?User $user, Model $line): bool
    {
        return $user !== null && $line->cost_owner_id !== null && (int) $line->cost_owner_id === $user->id;
    }

    public static function canSee(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(UserRole::Owner, UserRole::Admin)
            || ($user->hasRole(UserRole::Technician) && currentCompany()->technicians_see_costs);
    }
}
