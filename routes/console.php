<?php

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
