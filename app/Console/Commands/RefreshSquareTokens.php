<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PaymentProviderConnection;
use App\Payments\Square\SquareProvider;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Square access tokens expire after 30 days. Refresh the ones that expire soon, so a payment link
 * can be made on site even if nobody used Square for weeks. Scheduled daily (routes/console.php).
 */
#[Signature('payments:refresh-square-tokens')]
#[Description('Refresh Square access tokens that expire soon')]
class RefreshSquareTokens extends Command
{
    public function handle(SquareProvider $square, CurrentCompany $tenancy): int
    {
        $days = (int) config('payments.square.refresh_before_days', 7);
        $failed = 0;

        PaymentProviderConnection::withoutCompanyScope()
            ->where('provider', 'square')
            ->whereNotNull('refresh_token')
            ->where('token_expires_at', '<', now()->addDays($days))
            ->each(function (PaymentProviderConnection $connection) use ($square, $tenancy, &$failed) {
                try {
                    $company = Company::query()->findOrFail($connection->company_id);
                    $tenancy->runAs($company, fn () => $square->refresh($connection));
                    $this->line("Refreshed company {$connection->company_id}");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Company {$connection->company_id}: {$e->getMessage()}");
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
