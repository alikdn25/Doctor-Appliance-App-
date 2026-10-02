<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * A company created without a time zone takes it from its Owner's browser on their first visit.
 */
class DetectTimezoneController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit, Impersonation $impersonation): RedirectResponse
    {
        $company = currentCompany();

        Gate::authorize('update', $company);

        $validated = $request->validate(['timezone' => ['required', 'string', 'timezone:all']]);

        // Only once, and never from a super-admin's browser while impersonating.
        if (! $company->timezone_pending || $impersonation->isActive()) {
            return back();
        }

        $before = $company->timezone;
        $company->update(['timezone' => $validated['timezone'], 'timezone_pending' => false]);
        $audit->record('company.timezone_detected', $company, ['before' => $before, 'after' => $company->timezone]);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('company.timezone_detected', ['timezone' => $company->timezone])]);

        return back();
    }
}
