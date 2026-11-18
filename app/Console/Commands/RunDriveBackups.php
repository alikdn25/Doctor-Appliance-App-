<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Backups\DriveBackups;
use App\Support\Backups\GoogleDrive;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The nightly Google Drive backup (owner's task of November 19): from 03:00 company time, once a day, for each
 * company that connected its Drive. Runs from the scheduler every five minutes; a night missed by more than three
 * hours waits for the next one (the morning SMS then says "Backup failed").
 */
#[Signature('backup:drive')]
#[Description('Upload the nightly customers and jobs CSV files to each connected Google Drive')]
class RunDriveBackups extends Command
{
    public function handle(CurrentCompany $tenancy, DriveBackups $backups): int
    {
        if (! GoogleDrive::configured()) {
            return self::SUCCESS;
        }

        $count = 0;
        Company::query()->where('status', 'active')->whereNotNull('google_drive_token')->each(function (Company $company) use ($tenancy, $backups, &$count) {
            $now = CarbonImmutable::now($company->timezone);
            $at = $now->setTime(DriveBackups::HOUR, 0);

            if ($now->lessThan($at) || $now->greaterThan($at->addHours(3)) || $company->drive_backup_ran_on?->toDateString() === $now->toDateString()) {
                return;
            }

            $company->forceFill(['drive_backup_ran_on' => $now->toDateString()])->save();
            $tenancy->runAs($company, fn () => $backups->run($company, $now));
            $count++;
        });

        $this->info("Drive backups: {$count}");

        return self::SUCCESS;
    }
}
