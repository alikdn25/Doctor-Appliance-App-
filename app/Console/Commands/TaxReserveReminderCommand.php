<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use App\Support\Billing\Money;
use App\Support\Reports\TaxReserve;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use IntlDateFormatter;

/**
 * Runs every hour; from 9:00 on the day of the month each Owner picked, it tells them once how much to move to the
 * tax account for the month before (UX-REDESIGN §17.4), unless it was already moved.
 */
#[Signature('tax-reserve:remind')]
#[Description('Remind Owners how much to move to the tax account for last month')]
class TaxReserveReminderCommand extends Command
{
    public function handle(CurrentCompany $tenancy): int
    {
        $companies = Company::query()
            ->where('status', CompanyStatus::Active->value)
            ->where('tax_reserve_enabled', true)
            ->whereNotNull('tax_reserve_reminder_day')
            ->get();

        foreach ($companies as $company) {
            $local = CarbonImmutable::now($company->timezone);
            $month = $local->subMonthNoOverflow()->startOfMonth();

            if ($local->day < $company->tax_reserve_reminder_day || $local->hour < 9
                || $company->tax_reserve_reminded_month === $month->format('Y-m')) {
                continue;
            }

            $tenancy->runAs($company, function () use ($company, $month) {
                $owner = User::query()->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id)
                    ->where('is_active', true)->where('role', UserRole::Owner->value))->first();

                if ($owner === null) {
                    return;
                }

                $reserve = TaxReserve::month($month, $owner);

                if ($reserve['left'] > 0) {
                    $name = (new IntlDateFormatter(str_replace('-', '_', $company->locale), IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'LLLL yyyy'))
                        ->format($month->setTime(12, 0));
                    $amount = Money::format($reserve['left'], $reserve['currency'], $company->locale);

                    Alerts::owners($company, new AppAlert(
                        'tax_reserve',
                        __('tax_reserve.reminder.title', ['month' => $name]),
                        __('tax_reserve.reminder.body', ['amount' => $amount, 'month' => $name]),
                        route('tax-reserve.index', ['month' => $month->format('Y-m')]),
                    ));
                }
            });

            $company->forceFill(['tax_reserve_reminded_month' => $month->format('Y-m')])->save();
        }

        return self::SUCCESS;
    }
}
