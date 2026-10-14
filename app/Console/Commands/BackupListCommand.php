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
        $items = array_slice($backups->list($this->option('files') ? BackupManager::FILES : BackupManager::DATABASE), 0, (int) $this->option('limit'));

        $this->table(['Time (UTC)', 'Class', 'Size', 'Path'], array_map(fn (array $item) => [
            $item['at']->format('Y-m-d H:i'),
            $item['class'],
            number_format($item['size'] / 1048576, 1).' MB',
            $item['path'],
        ], $items));

        return self::SUCCESS;
    }
}
