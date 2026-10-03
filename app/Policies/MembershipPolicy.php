<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\User;
use App\Policies\Concerns\ChecksTenant;

class MembershipPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    /**
     * Owners cannot change or remove their own membership (prevents self-lockout).
     */
    public function update(User $user, Membership $membership): bool
    {
        return $this->inCurrentCompany($membership)
            && ($user->hasRole(UserRole::Owner) || ($user->hasRole(UserRole::Admin) && $membership->role === UserRole::Technician))
            && $membership->user_id !== $user->id;
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->update($user, $membership);
    }
}
