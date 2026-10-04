<?php

namespace App\Support\Billing;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Document purchase costs belong to their author. Shared job costs and profit: the Owner (and technicians when the
 * company allows it); the Office never sees them.
 */
class CostAccess
{
    public static function canEnterPrivate(?User $user): bool
    {
        // The Office never enters or sees purchase costs (Owner decision); technicians keep their own private costs.
        return $user !== null && $user->hasRole(UserRole::Owner, UserRole::Technician);
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

        return $user->hasRole(UserRole::Owner)
            || ($user->hasRole(UserRole::Technician) && currentCompany()->technicians_see_costs);
    }
}
