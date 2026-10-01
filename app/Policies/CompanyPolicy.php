<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

class CompanyPolicy
{
    /**
     * Company settings: only the Owner of the current company.
     */
    public function update(User $user, Company $company): bool
    {
        return app(CurrentCompany::class)->id() === $company->id
            && $user->hasRole(UserRole::Owner);
    }

    /**
     * Super-admin panel.
     */
    public function administer(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
