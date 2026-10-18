<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Impersonation;
use App\Sms\RoutedSmsProvider;
use App\Sms\SmsProvider;
use App\Sms\SmsProviders;
use App\Support\Audit\ActionSource;
use App\Support\Database\MigrationGuard;
use App\Support\Tenancy\CurrentCompany;
use App\Support\TimezoneDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use RuntimeException;

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
        $this->app->scoped(ActionSource::class);
        $this->app->bind(TimezoneDatabase::class, fn () => TimezoneDatabase::fromEnvironment());
        // The platform's SMS providers (config/sms.php): Telnyx for new numbers, each account its own provider.
        $this->app->singleton(SmsProviders::class);
        $this->app->bind(SmsProvider::class, RoutedSmsProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // The price book and Google review locations are kept by the office.
        Gate::define('manageCatalog', fn (User $user) => $user->hasRole(UserRole::Owner, UserRole::Admin));

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User && ! app(Impersonation::class)->isActive()) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }

            // A "remember me" cookie left by someone else on this device must not sign them back in later, when
            // this session ends (it silently switched accounts): drop it unless this sign-in sets its own.
            $inRequest = ! $this->app->runningInConsole() || $this->app->runningUnitTests();
            $guard = Auth::guard('web');
            if ($inRequest && ! $event->remember && $guard instanceof SessionGuard) {
                Cookie::queue(Cookie::forget($guard->getRecallerName()));
            }

            // Back must not show the previous person's pages.
            if ($inRequest) {
                Inertia::clearHistory();
            }
        });

        Event::listen(Logout::class, fn () => app(Impersonation::class)->finish());

        Event::listen(CommandStarting::class, fn (CommandStarting $event) => $this->guardProductionCommand($event));

        // The translation locale follows the current company (CurrentCompany); a long-running worker starts
        // every job on the default again, like the company context itself.
        Event::listen(JobProcessing::class, fn () => app()->setLocale((string) config('app.fallback_locale')));
    }

    /**
     * On the live server: no seeding (demo data) and no migration that drops or renames tables or columns, or deletes
     * rows, unless the owner approved it (database/approved-destructive-migrations.php). migrate:fresh, migrate:reset,
     * migrate:refresh, migrate:rollback and db:wipe are refused by prohibitDestructiveCommands() below.
     */
    private function guardProductionCommand(CommandStarting $event): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        if ($event->command === 'db:seed' || ($event->command === 'migrate' && $event->input->hasParameterOption('--seed'))) {
            throw new RuntimeException('Seeding is not allowed in production (it would add demo data to the live database).');
        }

        if ($event->command !== 'migrate') {
            return;
        }

        $migrator = $this->app->make('migrator');
        $files = $migrator->getMigrationFiles([database_path('migrations')]);
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $unapproved = MigrationGuard::unapproved(array_values(array_diff_key($files, array_flip($ran))));

        if ($unapproved !== []) {
            $list = collect($unapproved)->map(fn (array $ops, string $name) => $name.' ('.implode(', ', $ops).')')->implode('; ');

            throw new RuntimeException("Migration stopped: {$list} would remove data and is not approved by the owner (database/approved-destructive-migrations.php).");
        }
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
