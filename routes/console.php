<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Square access tokens last 30 days; keep every connected company's token fresh.
Schedule::command('payments:refresh-square-tokens')->dailyAt('03:17')->withoutOverlapping();
