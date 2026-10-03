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
        $destination = $company && $user->membershipFor($company)?->role === UserRole::Technician
            ? route('jobs.mine', absolute: false) : route('dashboard', absolute: false);

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($destination);
    }
}
