<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ImpersonationController extends Controller
{
    public function store(Request $request, Company $company, User $user, Impersonation $impersonation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        abort_if($user->membershipFor($company) === null || $user->isSuperAdmin(), 404);

        $impersonation->start($request->user(), $company, $user, $data['reason'] ?? null);

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $companyId = $impersonation->companyId();

        // Already ended (Return to admin pressed twice, or in an old tab): just go where it would have led.
        if (! $impersonation->isActive()) {
            return $request->user()?->isSuperAdmin()
                ? redirect()->route('admin.companies.index')
                : redirect()->route('dashboard');
        }

        $admin = $impersonation->stop();

        if ($admin === null) {
            return redirect()->route('login');
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => __('admin.impersonation_stopped')]);

        return redirect()->route('admin.companies.show', $companyId);
    }
}
