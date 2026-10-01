<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\User;
use App\Policies\Concerns\ChecksTenant;

class BrandPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->inCurrentCompany($brand) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Owner);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->inCurrentCompany($brand) && $user->hasRole(UserRole::Owner);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->update($user, $brand);
    }
}
