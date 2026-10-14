<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AuditLogger;
use App\Support\Audit\ActionSource;
use App\Support\Backups\BackupManager;
use App\Support\Backups\CompanyRestorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings one company's data back from a backup into the live database (other companies are not touched). Shows
 * what will be restored and asks for confirmation; a fresh backup of the live database is made before any change.
 */
#[Signature('backup:restore-company {company : Company id} {path? : Backup path from backup:list (default: the newest)} {--dry-run : Only show what would be restored} {--force : Do not ask (only after the owner approved the plan)}')]
#[Description('Restore one company from a database backup into the live database, without touching other companies')]
class BackupRestoreCompanyCommand extends Command
{
    public function handle(BackupManager $backups, CompanyRestorer $restorer, AuditLogger $audit, ActionSource $source): int
    {
        $companyId = (int) $this->argument('company');
        $path = $this->argument('path') ?: ($backups->list(BackupManager::DATABASE)[0]['path'] ?? null);

        if ($path === null) {
            $this->error('No database backups found.');

            return self::FAILURE;
        }

        $live = Company::query()->find($companyId);
        $this->info("Company {$companyId}".($live ? " ({$live->name})" : ' (not in the live database)')." from {$path}");

        try {
            $plan = $restorer->plan($path, $companyId);
            $changes = array_values(array_filter($plan, fn (array $row) => $row['add'] + $row['overwrite'] > 0));

            $this->table(['Table', 'Add back', 'Put back to backup values', 'Newer rows kept'], array_map(fn (array $row) => [
                $row['table'], $row['add'], $row['overwrite'], $row['newer'],
            ], $plan));

            if ($changes === []) {
                $this->info('Nothing to restore: the live data already matches the backup.');

                return self::SUCCESS;
            }

            if ($this->option('dry-run')) {
                $this->comment('Dry run: nothing changed.');

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->confirm('Restore these rows into the LIVE database?', false)) {
                $this->comment('Cancelled: nothing changed.');

                return self::SUCCESS;
            }

            $this->line('Backing up the live database first...');
            $safety = $backups->backupDatabase('restore');
            $this->line('Saved '.implode(', ', $safety->paths ?? []));

            $result = $source->as('console', fn () => $restorer->apply($companyId));
        } catch (Throwable $e) {
            $this->error(BackupManager::safeMessage($e));

            return self::FAILURE;
        } finally {
            $restorer->cleanup();
        }

        $audit->record('company.restored_from_backup', null, [
            'backup' => $path,
            'added' => $result['added'],
            'overwritten' => $result['overwritten'],
            'skipped' => count($result['skipped']),
        ], $companyId);

        $this->info("Done: {$result['added']} rows added back, {$result['overwritten']} rows put back.");

        foreach ($result['skipped'] as $skipped) {
            $this->warn("Skipped {$skipped}");
        }

        return self::SUCCESS;
    }
}
