<?php

namespace App\Policies;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\BusinessExpenseCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksTenant;

class BusinessExpenseCategoryPolicy
{
    use ChecksTenant;

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin, UserRole::Technician);
    }

    public function update(User $user, BusinessExpenseCategory $category): bool
    {
        return $this->inCurrentCompany($category) && $this->create($user)
            && ($user->canOffice(OfficePermission::Expenses) || $category->created_by === $user->id);
    }
}
