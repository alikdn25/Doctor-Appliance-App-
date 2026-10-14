<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DataSnapshot;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every hour; at 7:00 in each company's time zone it counts the company's customers, jobs and invoices and
 * compares them with the day before. A drop of more than 5% or of 10 records or more alerts the company's Owners.
 */
#[Signature('ops:watchdog {--now : Check every company now, whatever its local time}')]
#[Description('Count customers, jobs and invoices per company daily and alert the Owner on a sudden drop')]
class WatchdogCommand extends Command
{
    public function handle(CurrentCompany $tenancy): int
    {
        $settings = config('backup.watchdog');

        foreach (Company::query()->where('status', CompanyStatus::Active->value)->get() as $company) {
            $local = CarbonImmutable::now($company->timezone);

            if (! $this->option('now') && $local->hour !== (int) $settings['hour']) {
                continue;
            }

            $today = $local->toDateString();

            if (DataSnapshot::query()->where('company_id', $company->id)->whereDate('taken_on', $today)->exists()) {
                continue;
            }

            $counts = $tenancy->runAs($company, fn () => [
                'customers' => Customer::query()->count(),
                'jobs' => ServiceJob::query()->count(),
                'invoices' => Invoice::query()->count(),
            ]);

            $previous = DataSnapshot::query()->where('company_id', $company->id)->whereDate('taken_on', '<', $today)->orderByDesc('taken_on')->first();
            DataSnapshot::create(['company_id' => $company->id, 'taken_on' => $today, ...$counts]);

            if ($previous === null) {
                continue;
            }

            $drops = [];
            foreach ($counts as $key => $now) {
                $before = (int) $previous->{$key};
                $lost = $before - $now;

                if ($lost > 0 && ($lost >= (int) $settings['count'] || $lost / max(1, $before) * 100 > (float) $settings['percent'])) {
                    $drops[] = __("watchdog.{$key}", ['before' => $before, 'now' => $now]);
                }
            }

            $this->line("{$company->name}: ".json_encode($counts).($drops ? ' DROP' : ''));

            if ($drops !== []) {
                $tenancy->runAs($company, fn () => Alerts::owners($company, new AppAlert(
                    'data_drop',
                    __('watchdog.title', ['company' => $company->name]),
                    __('watchdog.body', ['changes' => implode(', ', $drops)]),
                    route('company.change-log'),
                )));
            }
        }

        return self::SUCCESS;
    }
}
