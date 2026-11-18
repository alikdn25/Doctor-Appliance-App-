<?php

namespace App\Console\Commands;

use App\Messaging\SmsThreads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: auto replies whose delay is over (when nobody replied meanwhile) and reminders about unanswered texts
 * (push + the notification list, business hours only).
 */
#[Signature('sms:sweep')]
#[Description('Send due auto replies and reminders about unanswered texts')]
class SweepSmsThreads extends Command
{
    public function handle(SmsThreads $threads): int
    {
        $counts = $threads->sweep();
        $this->info("Auto replies: {$counts['auto_replies']}, reminders: {$counts['reminders']}");

        return self::SUCCESS;
    }
}
