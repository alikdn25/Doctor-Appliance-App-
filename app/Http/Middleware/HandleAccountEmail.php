<?php

namespace App\Http\Middleware;

use App\Support\AccountEmail;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class HandleAccountEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('verification.notice') && $request->user()) {
            if (! AccountEmail::verificationRequired()) {
                return to_route('dashboard');
            }

            $user = $request->user();
            // Accounts created while mail was unavailable need their first link
            // when the operator enables confirmation. Refreshing must not send again.
            if (! $user->hasVerifiedEmail() && ! Cache::has($user->verificationDeliveryKey())) {
                $user->sendEmailVerificationNotification();
            }
        }

        if (! AccountEmail::deliveryEnabled() && $request->routeIs('password.email', 'verification.send')) {
            throw ValidationException::withMessages(['email' => __('auth.email_unavailable')]);
        }

        return $next($request);
    }
}
