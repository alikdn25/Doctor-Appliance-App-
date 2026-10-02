<?php

namespace App\Policies;

use App\Models\Appliance;
use App\Models\User;
use App\Policies\Concerns\ManagesCustomers;

class AppliancePolicy
{
    use ManagesCustomers;

    public function viewAny(User $user): bool
    {
        return $this->managesCustomers($user);
    }

    public function view(User $user, Appliance $appliance): bool
    {
        return $this->inCurrentCompany($appliance)
            && ($this->managesCustomers($user) || $this->seesThroughJobs($user, fn ($q) => $q->where('property_id', $appliance->property_id)));
    }

    public function create(User $user): bool
    {
        return $this->managesCustomers($user);
    }

    public function update(User $user, Appliance $appliance): bool
    {
        return $this->inCurrentCompany($appliance) && $this->managesCustomers($user);
    }

    public function delete(User $user, Appliance $appliance): bool
    {
        return $this->update($user, $appliance);
    }
}
