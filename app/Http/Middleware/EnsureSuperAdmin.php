<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // An admin page left open in another tab while support access is on: the session belongs to the
        // company member now. Explain how to get back instead of a bare 403.
        if (! $user?->isSuperAdmin() && $this->impersonation->isActive()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('admin.return_to_admin_first')]);

            return redirect()->route('dashboard');
        }

        abort_unless($user?->isSuperAdmin(), 403);

        return $next($request);
    }
}
