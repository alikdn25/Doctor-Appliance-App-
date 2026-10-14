<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Models\BotToken;
use App\Models\Company;
use App\Support\Audit\ActionSource;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website booking bot signs every request with its company's API token (Authorization: Bearer ...). The token
 * sets the company, the bot's user and the "bot" mark in the change log, and its own request limit.
 */
class AuthenticateBot
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly ActionSource $source,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? BotToken::findActive($plain) : null;

        if ($token === null || $token->user === null || ! $token->user->is_bot) {
            return response()->json(['message' => __('bot.errors.unauthenticated')], 401);
        }

        $company = Company::query()->find($token->company_id);

        if ($company === null || $company->status !== CompanyStatus::Active) {
            return response()->json(['message' => __('bot.errors.company_unavailable')], 403);
        }

        $key = "bot-requests:{$token->id}";

        if (RateLimiter::tooManyAttempts($key, max(1, $token->requests_per_minute))) {
            return response()->json(['message' => __('bot.errors.too_many_requests')], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $this->currentCompany->set($company);
        Auth::guard('web')->setUser($token->user);
        $this->source->set('bot');
        $request->attributes->set('bot_token', $token);
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
