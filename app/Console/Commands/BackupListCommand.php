<?php

namespace App\Console\Commands;

use App\Support\Backups\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:list {--files : List file backups} {--limit=30}')]
#[Description('List the backups in the external storage, newest first')]
class BackupListCommand extends Command
{
    public function handle(BackupManager $backups): int
    {
        // Without external storage there is nothing to list there: say so and show the local copies instead.
        if (! BackupManager::configured()) {
            $this->warn('External backups are not set up yet (BACKUP_ENCRYPTION_KEY and BACKUP_S3_*, docs/RESTORE.md).');

            if ($this->option('files')) {
                return self::SUCCESS;
            }

            $items = array_slice($backups->localList(), 0, (int) $this->option('limit'));
            $this->info($items === [] ? 'No local copies.' : 'Local copies on this server:');
        } else {
            $items = array_slice($backups->list($this->option('files') ? BackupManager::FILES : BackupManager::DATABASE), 0, (int) $this->option('limit'));
        }

        $this->table(['Time (UTC)', 'Class', 'Size', 'Path'], array_map(fn (array $item) => [
            $item['at']->format('Y-m-d H:i'),
            $item['class'],
            number_format($item['size'] / 1048576, 1).' MB',
            $item['path'],
        ], $items));

        return self::SUCCESS;
    }
}
