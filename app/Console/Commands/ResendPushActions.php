<?php

namespace App\Console\Commands;

use App\Support\Push\PushActions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: push notifications put off with "Later" come back.
 */
#[Signature('push:resend')]
#[Description('Send again the push notifications put off with "Later"')]
class ResendPushActions extends Command
{
    public function handle(): int
    {
        $this->info('Sent again: '.PushActions::resendDue());

        return self::SUCCESS;
    }
}
