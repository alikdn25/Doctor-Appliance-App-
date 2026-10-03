<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Impersonation;
use App\Sms\SmsProvider;
use App\Support\Tenancy\CurrentCompany;
use App\Support\TimezoneDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request / queued job.
        $this->app->scoped(CurrentCompany::class);
        $this->app->scoped(Impersonation::class);
        $this->app->bind(TimezoneDatabase::class, fn () => TimezoneDatabase::fromEnvironment());
        // The platform's SMS provider (config/sms.php): Twilio.
        $this->app->bind(SmsProvider::class, fn ($app) => $app->make(config('sms.provider')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User && ! app(Impersonation::class)->isActive()) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        Event::listen(Logout::class, fn () => app(Impersonation::class)->finish());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
