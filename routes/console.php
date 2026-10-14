<?php

use App\Support\Backups\BackupManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Square access tokens last 30 days; keep every connected company's token fresh.
Schedule::command('payments:refresh-square-tokens')->dailyAt('03:17')->withoutOverlapping();

// Customer messages (SPEC §7.7, §8).
Schedule::command('messages:deliver-due')->everyMinute()->withoutOverlapping();
Schedule::command('messages:send-visit-reminders')->hourlyAt(2)->withoutOverlapping();
Schedule::command('estimates:send-followups')->hourlyAt(7)->withoutOverlapping();
Schedule::command('sms:sync-registrations')->dailyAt('06:23');

// Technicians are reminded before visits with a strict arrival time.
Schedule::command('visits:strict-arrival-reminders')->everyFiveMinutes()->withoutOverlapping();

// Data protection (docs/RESTORE.md): the database every hour, uploaded files every day, both encrypted in external
// storage; a check after each run alerts the platform owner when the newest backup is missing or too old.
// Nothing runs until the storage keys and the encryption key are in .env.
Schedule::command('backup:run')->hourlyAt(5)->withoutOverlapping(55)->when(fn () => BackupManager::configured());
Schedule::command('backup:run --files --reason=daily')->dailyAt('03:35')->withoutOverlapping(180)->when(fn () => BackupManager::configured());
Schedule::command('backup:check --quiet-ok')->hourlyAt(50)->when(fn () => BackupManager::configured());

// Watchdog: at 7:00 company time, alert the Owner when customers, jobs or invoices suddenly drop.
Schedule::command('ops:watchdog')->hourlyAt(0)->withoutOverlapping();
