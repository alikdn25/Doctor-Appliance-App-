<?php

namespace App\Console\Commands;

use App\Models\UsageEvent;
use Illuminate\Console\Command;

/**
 * The action log is kept 90 days.
 */
class PruneUsageEvents extends Command
{
    protected $signature = 'usage:prune';

    protected $description = 'Delete usage events older than 90 days';

    public function handle(): int
    {
        $deleted = UsageEvent::withoutCompanyScope()->where('occurred_at', '<', now()->subDays(UsageEvent::KEEP_DAYS))->delete();
        $this->info("Deleted {$deleted} usage events.");

        return self::SUCCESS;
    }
}
