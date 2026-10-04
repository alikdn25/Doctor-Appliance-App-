<?php

namespace App\Policies;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\User;

class MessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin) && $user->canOffice(OfficePermission::Messages);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
