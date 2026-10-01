<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owners, Admins and super-admins must enable 2FA before using the app (SPEC §9).
 * Runs after SetCurrentCompany so the current role is known.
 */
class EnsureTwoFactorEnabled
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (
            $user === null
            || ! config('fieldservice.require_two_factor')
            || $this->impersonation->isActive()
            || $user->hasEnabledTwoFactorAuthentication()
        ) {
            return $next($request);
        }

        $required = $user->isSuperAdmin() || ($user->currentRole()?->requiresTwoFactor() ?? false);

        if (! $required) {
            return $next($request);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('auth.two_factor_required')]);

        return redirect()->route('security.edit');
    }
}
