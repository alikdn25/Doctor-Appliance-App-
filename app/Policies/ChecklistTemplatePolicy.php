<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class ChecklistTemplatePolicy
{
    /**
     * Checklists per job type are set by the office.
     */
    public function manage(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }
}
