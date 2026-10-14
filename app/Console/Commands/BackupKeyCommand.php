<?php

namespace App\Console\Commands;

use App\Support\Backups\BackupEncryptor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:key')]
#[Description('Print a new backup encryption key for BACKUP_ENCRYPTION_KEY (does not change .env)')]
class BackupKeyCommand extends Command
{
    public function handle(): int
    {
        $this->line(BackupEncryptor::generateKey());
        $this->warn('Put it in .env as BACKUP_ENCRYPTION_KEY and keep a copy outside the server: without it no backup can be read.');

        return self::SUCCESS;
    }
}
