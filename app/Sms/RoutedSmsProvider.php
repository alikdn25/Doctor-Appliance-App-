<?php

namespace App\Sms;

use App\Models\Company;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;

/**
 * What the app injects as SmsProvider: work on an existing account goes to that account's provider, everything else
 * (new numbers, "is SMS set up") to the default provider. Webhooks pick their provider by URL (SmsWebhookController).
 */
class RoutedSmsProvider implements SmsProvider
{
    public function __construct(private readonly SmsProviders $providers, private readonly CurrentCompany $tenancy) {}

    public function key(): string
    {
        return $this->providers->default()->key();
    }

    public function isConfigured(): bool
    {
        return $this->providers->default()->isConfigured();
    }

    public function provision(Company $company): SmsAccount
    {
        $existing = $this->tenancy->runAs($company, fn () => SmsAccount::query()->first());

        return ($existing && $this->providers->has($existing->provider)
            ? $this->providers->get($existing->provider)
            : $this->providers->default())->provision($company);
    }

    public function send(SmsAccount $account, string $to, string $body): string
    {
        return $this->providers->get($account->provider)->send($account, $to, $body);
    }

    public function verifyWebhook(Request $request, SmsAccount $account): bool
    {
        return $this->providers->get($account->provider)->verifyWebhook($request, $account);
    }

    public function accountForWebhook(Request $request): ?SmsAccount
    {
        return $this->providers->default()->accountForWebhook($request);
    }

    public function inbound(Request $request): array
    {
        return $this->providers->default()->inbound($request);
    }

    public function statusUpdate(Request $request): array
    {
        return $this->providers->default()->statusUpdate($request);
    }

    public function registrationStatus(SmsRegistration $registration, SmsAccount $account): ?array
    {
        return $this->providers->get($account->provider)->registrationStatus($registration, $account);
    }
}
