<?php

namespace App\Console\Commands;

use App\Support\Backups\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hourly database backups and daily file backups (routes/console.php); "deploy" before migrations (deploy.sh).
 * A failure alerts the platform owner and exits with an error, so the deploy script stops.
 */
#[Signature('backup:run {--files : Back up uploaded files instead of the database} {--reason=hourly : hourly, deploy, restore or manual}')]
#[Description('Back up the database (or uploaded files) to the external storage, encrypted')]
class BackupRunCommand extends Command
{
    public function handle(BackupManager $backups): int
    {
        $reason = (string) $this->option('reason');

        if (! in_array($reason, ['hourly', 'daily', 'deploy', 'restore', 'manual'], true)) {
            $this->error('Unknown reason.');

            return self::INVALID;
        }

        if (! BackupManager::configured()) {
            if ($this->option('files') || ! in_array($reason, ['deploy', 'manual'], true)) {
                $this->error('Backups are not set up: BACKUP_ENCRYPTION_KEY and the BACKUP_S3_* storage settings are missing (docs/RESTORE.md).');

                return self::FAILURE;
            }

            // Before a deployment there must be a copy even before external storage is set up.
            try {
                $path = $backups->localDump($reason);
            } catch (Throwable $e) {
                $this->error(BackupManager::safeMessage($e));

                return self::FAILURE;
            }

            $this->warn("External backups are not set up yet; saved a local copy only: {$path}");

            return self::SUCCESS;
        }

        try {
            $run = $this->option('files') ? $backups->backupFiles($reason) : $backups->backupDatabase($reason);
        } catch (Throwable $e) {
            $this->error(BackupManager::safeMessage($e));

            return self::FAILURE;
        }

        foreach ($run->paths ?? [] as $path) {
            $this->line("Saved {$path} (".number_format(($run->size_bytes ?? 0) / 1048576, 1).' MB)');
        }

        if (config('backup.prune') && ! $this->option('files') && $reason === 'hourly') {
            try {
                $deleted = $backups->prune();
                $this->line('Expired backups deleted: '.count($deleted));
            } catch (Throwable $e) {
                // The backup itself is safe; a key without delete permission only leaves old copies behind.
                $this->warn('Could not delete expired backups: '.BackupManager::safeMessage($e));
            }
        }

        return self::SUCCESS;
    }
}
