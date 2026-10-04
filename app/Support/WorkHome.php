<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

/**
 * Where work opens: the office goes straight to the calendar to look and book, technicians to My jobs.
 */
class WorkHome
{
    public static function url(User $user, ?Company $company = null): string
    {
        $company ??= $user->accessibleCompanies()->firstWhere('id', $user->current_company_id) ?? $user->accessibleCompanies()->first();

        return match ($company ? $user->membershipFor($company)?->role : null) {
            UserRole::Owner, UserRole::Admin => route('calendar', absolute: false),
            UserRole::Technician => route('jobs.mine', absolute: false),
            default => route('dashboard', absolute: false),
        };
    }
}
