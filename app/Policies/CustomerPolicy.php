<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ManagesCustomers;

class CustomerPolicy
{
    use ManagesCustomers;

    public function viewAny(User $user): bool
    {
        return $this->seesCustomers($user);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->inCurrentCompany($customer)
            && ($this->seesCustomers($user) || $this->seesThroughJobs($user, fn ($q) => $q->where('customer_id', $customer->id)));
    }

    public function create(User $user): bool
    {
        return $this->managesCustomers($user);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->inCurrentCompany($customer) && $this->managesCustomers($user);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }
}
