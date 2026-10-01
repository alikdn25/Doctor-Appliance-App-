<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use App\Policies\Concerns\ManagesCustomers;

class PropertyPolicy
{
    use ManagesCustomers;

    public function viewAny(User $user): bool
    {
        return $this->managesCustomers($user);
    }

    public function view(User $user, Property $property): bool
    {
        return $this->inCurrentCompany($property) && $this->managesCustomers($user);
    }

    public function create(User $user): bool
    {
        return $this->managesCustomers($user);
    }

    public function update(User $user, Property $property): bool
    {
        return $this->view($user, $property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $this->view($user, $property);
    }
}
