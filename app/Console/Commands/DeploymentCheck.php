<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('app:deployment-check {--before-migrate : Skip schema and asset checks during installation}')]
#[Description('Check production settings, database, writable storage and built assets without printing secrets')]
class DeploymentCheck extends Command
{
    public function handle(Migrator $migrator): int
    {
        $url = (string) config('app.url');
        $checks = [
            'APP_ENV=production' => config('app.env') === 'production',
            'APP_DEBUG=false' => config('app.debug') === false,
            'APP_KEY configured' => filled(config('app.key')),
            'APP_URL is an HTTPS domain' => parse_url($url, PHP_URL_SCHEME) === 'https' && filled(parse_url($url, PHP_URL_HOST)) && ! in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1'], true),
            'Secure session cookie' => config('session.secure') === true,
            'PHP 8.3 or later' => PHP_VERSION_ID >= 80300,
            'Required PHP extensions' => count(array_filter(['pdo_pgsql', 'mbstring', 'intl', 'gd', 'bcmath', 'zip'], fn (string $name) => ! extension_loaded($name))) === 0,
            'Writable storage' => is_writable(storage_path()) && is_writable(storage_path('app')) && is_writable(storage_path('framework')),
            'Writable bootstrap cache' => is_writable(base_path('bootstrap/cache')),
        ];
        try {
            $connection = DB::connection();
            $connection->getPdo();
            $checks['PostgreSQL connection'] = $connection->getDriverName() === 'pgsql';
            if (! $this->option('before-migrate')) {
                $checks['No pending migrations'] = $migrator->getRepository()->repositoryExists()
                    && array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan()) === [];
            }
        } catch (Throwable) {
            // Connection errors can include credentials; report only the failed check.
            $checks['Database connection and migrations'] = false;
        }
        if (! $this->option('before-migrate')) {
            $checks['Built frontend assets'] = is_file(public_path('build/manifest.json'));
        }
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? 'PASS ' : 'FAIL ').$label);
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->warn('Email uses a test transport; customer emails will not be delivered.');
        }
        $this->line('Verify the queue worker, minute scheduler, HTTPS and backup restore separately.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
