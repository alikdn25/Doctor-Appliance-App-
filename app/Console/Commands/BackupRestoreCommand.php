<?php

namespace App\Console\Commands;

use App\Support\Backups\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Restores a whole database backup into another database (a test restore, or a copy to compare). It never writes
 * into the live database; docs/RESTORE.md explains how to switch the app to a restored copy.
 */
#[Signature('backup:restore {path? : Backup path from backup:list (default: the newest)} {--database= : Target database (default: BACKUP_RESTORE_DATABASE or <live>_restore)}')]
#[Description('Restore a database backup into a separate database and print record counts')]
class BackupRestoreCommand extends Command
{
    public function handle(BackupManager $backups): int
    {
        $path = $this->argument('path') ?: ($backups->list(BackupManager::DATABASE)[0]['path'] ?? null);

        if ($path === null) {
            $this->error('No database backups found.');

            return self::FAILURE;
        }

        try {
            $database = $backups->restoreDatabase($path, $this->option('database') ?: null);
        } catch (Throwable $e) {
            $this->error(BackupManager::safeMessage($e));

            return self::FAILURE;
        }

        $this->info("Restored {$path} into database {$database}.");

        $connection = DB::connection($backups->connectionTo($database));
        $rows = [];
        foreach (['companies', 'users', 'customers', 'service_jobs', 'estimates', 'invoices', 'payments'] as $table) {
            $rows[] = [$table, $connection->table($table)->count()];
        }
        $this->table(['Table', 'Rows'], $rows);
        $connection->disconnect();

        return self::SUCCESS;
    }
}
