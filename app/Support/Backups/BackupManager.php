<?php

namespace App\Support\Backups;

use App\Models\BackupRun;
use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Makes, lists, checks, prunes and restores the encrypted backups in external storage.
 *
 * Layout in the bucket (prefix = BACKUP_PREFIX):
 *   database/hourly/2026/10/14/db-20261014-130002-hourly.dump.enc   kept 48 hours
 *   database/daily/2026/10/14/db-...dump.enc                        the first of each day, kept 30 days
 *   database/monthly/2026/10/db-...dump.enc                         the first of each month, kept 12 months
 *   database/event/2026/10/14/db-...-deploy.dump.enc                before a deployment or a restore, 30 days
 *   files/daily/2026/10/14/files-20261014-031500.tar.gz.enc         uploaded files, kept 30 days
 *
 * Database dumps are PostgreSQL custom-format dumps (pg_restore).
 */
class BackupManager
{
    public const DATABASE = 'database';

    public const FILES = 'files';

    /** Reasons that go to database/event instead of the hourly/daily/monthly rotation. */
    private const EVENT_REASONS = ['deploy', 'restore', 'manual'];

    /** External storage and the encryption key are set (.env); until then only local deploy dumps are made. */
    public static function configured(): bool
    {
        $disk = config('filesystems.disks.'.config('backup.disk'));

        return filled(config('backup.encryption_key'))
            && is_array($disk)
            && ($disk['driver'] !== 's3' || (filled($disk['bucket'] ?? null) && filled($disk['key'] ?? null)));
    }

    /**
     * A plain dump on this server before a deployment, while external storage is not set up yet. Keeps the newest
     * five. Only the application's user can read them.
     */
    public function localDump(string $reason): string
    {
        $directory = storage_path('app/local-backups');
        File::ensureDirectoryExists($directory, 0700);
        $target = "{$directory}/db-".now('UTC')->format('Ymd-His')."-{$reason}.dump";
        $this->pgDump($this->liveDatabase(), $target);
        @chmod($target, 0600);

        collect(File::files($directory))
            ->sortByDesc(fn ($file) => $file->getFilename())
            ->slice(5)
            ->each(fn ($file) => File::delete($file->getPathname()));

        return $target;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('backup.disk'));
    }

    /**
     * Dumps the live database, encrypts the dump and uploads it. Throws (after recording the failure and alerting)
     * when anything goes wrong, so a caller such as the deploy script stops.
     */
    public function backupDatabase(string $reason = 'hourly'): BackupRun
    {
        return $this->run(self::DATABASE, $reason, function (string $work, CarbonImmutable $now) use ($reason) {
            $dump = "{$work}/db.dump";
            $this->pgDump($this->liveDatabase(), $dump);

            $name = 'db-'.$now->format('Ymd-His')."-{$reason}.dump.enc";
            $encrypted = "{$work}/{$name}";
            (new BackupEncryptor)->encryptFile($dump, $encrypted);

            $paths = [];

            if (in_array($reason, self::EVENT_REASONS, true)) {
                $paths[] = $this->upload($encrypted, $this->path('database/event/'.$now->format('Y/m/d')."/{$name}"));
            } else {
                $paths[] = $this->upload($encrypted, $this->path('database/hourly/'.$now->format('Y/m/d')."/{$name}"));

                // The first good backup of a day and of a month is kept longer.
                if ($this->disk()->files($this->path('database/daily/'.$now->format('Y/m/d'))) === []) {
                    $paths[] = $this->upload($encrypted, $this->path('database/daily/'.$now->format('Y/m/d')."/{$name}"));
                }

                if ($this->disk()->files($this->path('database/monthly/'.$now->format('Y/m'))) === []) {
                    $paths[] = $this->upload($encrypted, $this->path('database/monthly/'.$now->format('Y/m')."/{$name}"));
                }
            }

            return [$paths, (int) filesize($encrypted)];
        });
    }

    /**
     * Archives the uploaded files (photos, receipts, signatures, PDFs, logos), encrypts and uploads the archive.
     */
    public function backupFiles(string $reason = 'daily'): BackupRun
    {
        return $this->run(self::FILES, $reason, function (string $work, CarbonImmutable $now) {
            $roots = array_filter((array) config('backup.file_roots'), fn (string $root) => is_dir($root));
            $archive = "{$work}/files.tar.gz";
            $arguments = [(string) config('backup.binaries.tar'), '-czf', $archive];

            foreach ($roots as $name => $root) {
                $arguments = [...$arguments, '-C', dirname($root), basename($root)];
            }

            if ($roots === []) {
                $arguments = [...$arguments, '--files-from', '/dev/null'];
            }

            $this->runProcess($arguments, 'tar');

            $name = 'files-'.$now->format('Ymd-His').'.tar.gz.enc';
            $encrypted = "{$work}/{$name}";
            (new BackupEncryptor)->encryptFile($archive, $encrypted);

            return [[$this->upload($encrypted, $this->path('files/daily/'.$now->format('Y/m/d')."/{$name}"))], (int) filesize($encrypted)];
        });
    }

    /**
     * Backups in storage, newest first.
     *
     * @return list<array{path: string, kind: string, class: string, at: CarbonImmutable, size: int}>
     */
    public function list(?string $kind = null): array
    {
        $kinds = $kind === null ? [self::DATABASE, self::FILES] : [$kind];
        $items = [];

        foreach ($kinds as $k) {
            foreach ($this->disk()->allFiles($this->path($k)) as $path) {
                $at = self::timeOf($path);

                if ($at === null) {
                    continue;
                }

                $items[] = [
                    'path' => $path,
                    'kind' => $k,
                    'class' => Str::of($path)->after($this->path($k).'/')->before('/')->toString(),
                    'at' => $at,
                    'size' => (int) $this->disk()->size($path),
                ];
            }
        }

        usort($items, fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return $items;
    }

    /**
     * Deletes backups past their retention. Returns the deleted paths.
     *
     * @return list<string>
     */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $keep = config('backup.retention');
        $limits = [
            'hourly' => $now->subHours($keep['hourly_hours']),
            'daily' => $now->subDays($keep['daily_days']),
            'monthly' => $now->subMonths($keep['monthly_months'])->startOfMonth(),
            'event' => $now->subDays($keep['event_days']),
        ];
        $deleted = [];

        foreach ($this->list() as $item) {
            $limit = $item['kind'] === self::FILES ? $now->subDays($keep['files_days']) : ($limits[$item['class']] ?? null);

            if ($limit !== null && $item['at']->lessThan($limit)) {
                $this->disk()->delete($item['path']);
                $deleted[] = $item['path'];
            }
        }

        return $deleted;
    }

    /**
     * Problems with the backups (none = healthy): the newest database backup is too old, the newest files backup is
     * older than 26 hours, or the storage cannot be read.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        try {
            $items = collect($this->list());
        } catch (Throwable $e) {
            return [__('backups.problems.storage', ['error' => self::safeMessage($e)])];
        }

        $problems = [];
        $database = $items->firstWhere('kind', self::DATABASE);
        $files = $items->firstWhere('kind', self::FILES);

        if ($database === null || $database['at']->lessThan(now()->subMinutes((int) config('backup.max_age_minutes')))) {
            $problems[] = __('backups.problems.database_old', ['time' => $database ? $database['at']->diffForHumans() : __('backups.never')]);
        }

        if ($files === null || $files['at']->lessThan(now()->subHours(26))) {
            $problems[] = __('backups.problems.files_old', ['time' => $files ? $files['at']->diffForHumans() : __('backups.never')]);
        }

        return $problems;
    }

    /**
     * Downloads and decrypts a backup into a local file and returns its path. The caller deletes it.
     */
    public function fetch(string $path, string $target): string
    {
        if (! $this->disk()->exists($path)) {
            throw new RuntimeException("Backup not found: {$path}");
        }

        $encrypted = "{$target}.enc";
        $in = $this->disk()->readStream($path);
        $out = fopen($encrypted, 'wb');

        if ($in === null || $out === false) {
            throw new RuntimeException("Cannot read backup: {$path}");
        }

        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);

        try {
            (new BackupEncryptor)->decryptFile($encrypted, $target);
        } finally {
            @unlink($encrypted);
        }

        return $target;
    }

    /**
     * Restores a database backup into another database (by default the scratch database). Never the live one.
     */
    public function restoreDatabase(string $path, ?string $database = null): string
    {
        $database ??= $this->scratchDatabase();

        if ($database === $this->liveDatabase()) {
            throw new RuntimeException('Refusing to restore over the live database. Choose another database name.');
        }

        $work = $this->workDirectory();

        try {
            $dump = $this->fetch($path, "{$work}/restore.dump");
            $this->recreateDatabase($database);
            $this->runProcess([
                (string) config('backup.binaries.pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error',
                ...$this->connectionArguments(), '--dbname', $database, $dump,
            ], 'pg_restore');
        } finally {
            File::deleteDirectory($work);
        }

        return $database;
    }

    public function liveDatabase(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    public function scratchDatabase(): string
    {
        return (string) (config('backup.restore_database') ?: $this->liveDatabase().'_restore');
    }

    /** A database connection to the given database on the live server (same credentials). */
    public function connectionTo(string $database): string
    {
        $name = 'backup_'.$database;
        config(["database.connections.{$name}" => [...config('database.connections.'.config('database.default')), 'database' => $database]]);
        DB::purge($name);

        return $name;
    }

    /**
     * @param  callable(string, CarbonImmutable): array{0: list<string>, 1: int}  $work
     */
    private function run(string $kind, string $reason, callable $work): BackupRun
    {
        $record = BackupRun::create(['kind' => $kind, 'reason' => $reason, 'status' => 'running', 'started_at' => now()]);
        $directory = $this->workDirectory();

        try {
            [$paths, $size] = $work($directory, CarbonImmutable::now('UTC'));
            $record->update(['status' => 'succeeded', 'paths' => $paths, 'size_bytes' => $size, 'finished_at' => now()]);

            return $record;
        } catch (Throwable $e) {
            $message = self::safeMessage($e);
            $record->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);

            Alerts::platform(new AppAlert(
                'backup_failed',
                __('backups.alerts.failed_title', ['kind' => __("backups.kinds.{$kind}")]),
                __('backups.alerts.failed_body', ['app' => config('app.name'), 'error' => $message]),
            ));

            throw new RuntimeException("Backup failed: {$message}", previous: $e);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function pgDump(string $database, string $target): void
    {
        $this->runProcess([
            (string) config('backup.binaries.pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--compress=6',
            ...$this->connectionArguments(), '--file', $target, $database,
        ], 'pg_dump');
    }

    private function recreateDatabase(string $database): void
    {
        $quoted = '"'.str_replace('"', '""', $database).'"';
        $psql = [(string) config('backup.binaries.psql'), ...$this->connectionArguments(), '--dbname', $this->liveDatabase(), '-v', 'ON_ERROR_STOP=1'];
        $this->runProcess([...$psql, '-c', "DROP DATABASE IF EXISTS {$quoted}"], 'psql');
        $this->runProcess([...$psql, '-c', "CREATE DATABASE {$quoted}"], 'psql');
    }

    /**
     * @return list<string>
     */
    private function connectionArguments(): array
    {
        $config = config('database.connections.'.config('database.default'));

        return array_values(array_filter([
            filled($config['host'] ?? null) ? '--host='.$config['host'] : null,
            filled($config['port'] ?? null) ? '--port='.$config['port'] : null,
            filled($config['username'] ?? null) ? '--username='.$config['username'] : null,
            '--no-password',
        ]));
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $label): void
    {
        $password = (string) (config('database.connections.'.config('database.default').'.password') ?? '');
        $result = Process::timeout(3600)->env(['PGPASSWORD' => $password, 'PGCONNECT_TIMEOUT' => '15'])->run($command);

        if ($result->failed()) {
            throw new RuntimeException("{$label} failed: ".Str::limit(trim($result->errorOutput() ?: $result->output()), 500));
        }
    }

    private function upload(string $local, string $path): string
    {
        $stream = fopen($local, 'rb');

        try {
            $this->disk()->writeStream($path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        // Read back the size: an upload that silently lost bytes is a failed backup.
        if ((int) $this->disk()->size($path) !== (int) filesize($local)) {
            throw new RuntimeException("Uploaded size does not match for {$path}");
        }

        return $path;
    }

    private function path(string $relative): string
    {
        return ltrim(config('backup.prefix').'/'.$relative, '/');
    }

    private function workDirectory(): string
    {
        $directory = storage_path('app/backup-work/'.Str::random(16));
        File::ensureDirectoryExists($directory, 0700);

        return $directory;
    }

    public static function timeOf(string $path): ?CarbonImmutable
    {
        if (! preg_match('/-(\d{8}-\d{6})[.-]/', basename($path), $match)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd-His', $match[1], 'UTC') ?: null;
    }

    /** An error message without the database password or storage keys. */
    public static function safeMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        foreach ([config('database.connections.'.config('database.default').'.password'), config('filesystems.disks.backups.secret'), config('backup.encryption_key')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return Str::limit($message, 1000);
    }
}
