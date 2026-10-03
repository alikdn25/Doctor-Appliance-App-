<?php

use App\Http\Middleware\EnsureAccountEmailVerified;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Payment provider webhooks are signed by the provider instead of a CSRF token.
        $middleware->preventRequestForgery(except: ['webhooks/*']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'verified' => EnsureAccountEmailVerified::class,
            'tenant' => SetCurrentCompany::class,
            'role' => EnsureRole::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        // The tenant must be known before route model binding runs, so that
        // tenant-scoped models ({brand}, {membership}, ...) resolve inside it.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetCurrentCompany::class);
        $middleware->prependToPriorityList(SetCurrentCompany::class, EnsureAccountEmailVerified::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['owner_password', 'owner_password_confirmation']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
