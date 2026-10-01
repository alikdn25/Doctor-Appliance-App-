<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:owner,admin'). Checks the role of the current membership.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        abort_unless($user?->hasRole(...$allowed), 403);

        return $next($request);
    }
}
