<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

class WorkLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $user = $request->user();
        $companies = $user->accessibleCompanies();
        $company = $companies->firstWhere('id', $user->current_company_id) ?? $companies->first();
        // Owners and Admins start on Jobs, technicians on their own jobs.
        $destination = match ($company ? $user->membershipFor($company)?->role : null) {
            UserRole::Owner, UserRole::Admin => route('jobs.index', absolute: false),
            UserRole::Technician => route('jobs.mine', absolute: false),
            default => route('dashboard', absolute: false),
        };

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($destination);
    }
}
