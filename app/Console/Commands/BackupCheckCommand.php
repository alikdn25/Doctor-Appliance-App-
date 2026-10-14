<?php

namespace App\Console\Commands;

use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use App\Support\Backups\BackupManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every hour after the backup: the newest backups must be recent and readable. Alerts the platform owner when
 * they are not (also when the scheduler or the backup itself silently stopped).
 */
#[Signature('backup:check {--quiet-ok : Print nothing when all is well} {--no-alert : Only report, do not notify}')]
#[Description('Check that recent database and file backups exist in the external storage')]
class BackupCheckCommand extends Command
{
    public function handle(BackupManager $backups): int
    {
        $problems = $backups->problems();

        if ($problems === []) {
            if (! $this->option('quiet-ok')) {
                $this->info('Backups OK.');
                $latest = collect($backups->list())->groupBy('kind')->map->first();
                foreach ($latest as $kind => $item) {
                    $this->line("Newest {$kind}: {$item['at']->format('Y-m-d H:i')} UTC ({$item['path']})");
                }
            }

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if (! $this->option('no-alert')) {
            Alerts::platform(new AppAlert('backup_check', __('backups.alerts.check_title'), implode("\n", $problems)));
        }

        return self::FAILURE;
    }
}
