<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary "no password" mode: while AUTH_AUTO_LOGIN_EMAIL is set, every visitor is signed in as that
 * account, without password or two-factor code. Anyone with the address then sees that account's data.
 * Remove the setting to bring sign-in back.
 */
class AutoLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = config('auth.auto_login_email');

        if (filled($email) && Auth::guard('web')->guest()) {
            $user = User::query()
                ->where('email', mb_strtolower(trim((string) $email)))
                ->where('is_active', true)
                ->first();

            if ($user !== null) {
                Auth::guard('web')->login($user, remember: true);
                $request->session()->regenerate();
            }
        }

        return $next($request);
    }
}
