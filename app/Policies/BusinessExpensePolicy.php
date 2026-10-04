<?php

namespace App\Policies;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\BusinessExpense;
use App\Models\User;
use App\Policies\Concerns\ChecksTenant;

class BusinessExpensePolicy
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

    public function view(User $user, BusinessExpense $expense): bool
    {
        return $this->inCurrentCompany($expense) && $this->viewAny($user)
            && ($user->canOffice(OfficePermission::Expenses) || $expense->created_by === $user->id);
    }

    public function update(User $user, BusinessExpense $expense): bool
    {
        return $this->view($user, $expense);
    }

    public function delete(User $user, BusinessExpense $expense): bool
    {
        return $this->view($user, $expense);
    }
}
