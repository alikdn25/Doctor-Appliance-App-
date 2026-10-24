<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksTenant;

/**
 * Everyone in the company keeps their own tasks; Owners and Admins also give tasks to teammates and see everyone's.
 * A task is changed by the one it is for, the one who gave it and the office.
 */
class TaskPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin, UserRole::Technician, UserRole::Collector);
    }

    public function viewAll(User $user): bool
    {
        return $user->hasRole(UserRole::Owner, UserRole::Admin);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Giving a task to someone else. */
    public function assignOthers(User $user): bool
    {
        return $this->viewAll($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->inCurrentCompany($task) && $this->viewAny($user)
            && ($this->viewAll($user) || $task->user_id === $user->id || $task->created_by === $user->id);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
