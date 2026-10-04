<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

/**
 * Where work opens: the office goes straight to the calendar (to book, or just to look when view-only),
 * technicians to My jobs.
 */
class WorkHome
{
    public static function url(User $user, ?Company $company = null): string
    {
        $company ??= $user->accessibleCompanies()->firstWhere('id', $user->current_company_id) ?? $user->accessibleCompanies()->first();

        $membership = $company ? $user->membershipFor($company) : null;

        return match (true) {
            $membership?->role === UserRole::Technician => route('jobs.mine', absolute: false),
            in_array($membership?->role, [UserRole::Owner, UserRole::Admin], true) => route('calendar', absolute: false),
            default => route('dashboard', absolute: false),
        };
    }
}
