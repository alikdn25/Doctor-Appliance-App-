<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        return $request->routeIs('register.store')
            ? app(ThrottleRequests::class)->handle($request, $next, 'registration')
            : $next($request);
    }
}
