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
        return $this->managesCustomers($user);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->inCurrentCompany($customer) && $this->managesCustomers($user);
    }

    public function create(User $user): bool
    {
        return $this->managesCustomers($user);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }
}
