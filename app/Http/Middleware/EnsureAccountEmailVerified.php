<?php

namespace App\Http\Middleware;

use App\Support\AccountEmail;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountEmailVerified
{
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        return AccountEmail::verificationRequired()
            ? app(EnsureEmailIsVerified::class)->handle($request, $next, $redirectToRoute)
            : $next($request);
    }
}
