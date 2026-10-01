<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TaxRate;
use App\Models\User;
use App\Policies\Concerns\ChecksTenant;

class TaxRatePolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Owner);
    }

    public function update(User $user, TaxRate $taxRate): bool
    {
        return $this->inCurrentCompany($taxRate) && $user->hasRole(UserRole::Owner);
    }

    public function delete(User $user, TaxRate $taxRate): bool
    {
        return $this->update($user, $taxRate);
    }
}
