<?php

namespace App\Console\Commands;

use App\Support\Backups\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:prune')]
#[Description('Delete backups past their retention: hourly 48 hours, daily 30 days, monthly 12 months, files 30 days')]
class BackupPruneCommand extends Command
{
    public function handle(BackupManager $backups): int
    {
        $deleted = $backups->prune();

        foreach ($deleted as $path) {
            $this->line("Deleted {$path}");
        }

        $this->info(count($deleted).' expired backups deleted.');

        return self::SUCCESS;
    }
}
