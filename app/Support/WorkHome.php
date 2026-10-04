<?php

namespace App\Support;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

/**
 * Where work opens: whoever schedules goes straight to the calendar to look and book, technicians to My jobs,
 * an Office member without scheduling to the dashboard.
 */
class WorkHome
{
    public static function url(User $user, ?Company $company = null): string
    {
        $company ??= $user->accessibleCompanies()->firstWhere('id', $user->current_company_id) ?? $user->accessibleCompanies()->first();

        $membership = $company ? $user->membershipFor($company) : null;

        return match (true) {
            $membership?->role === UserRole::Technician => route('jobs.mine', absolute: false),
            (bool) $membership?->allows(OfficePermission::Schedule) => route('calendar', absolute: false),
            default => route('dashboard', absolute: false),
        };
    }
}
