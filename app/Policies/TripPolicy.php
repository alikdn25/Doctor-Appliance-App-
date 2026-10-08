<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\User;
use App\Policies\Concerns\ChecksTenant;

/**
 * Everyone who drives for the company keeps their own mileage log; Owners and Admins see everyone's.
 */
class TripPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin, UserRole::Technician);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Trip $trip): bool
    {
        return $this->inCurrentCompany($trip) && $this->viewAny($user)
            && ($user->hasRole(UserRole::Owner, UserRole::Admin) || $trip->user_id === $user->id);
    }

    public function delete(User $user, Trip $trip): bool
    {
        return $this->update($user, $trip);
    }
}
