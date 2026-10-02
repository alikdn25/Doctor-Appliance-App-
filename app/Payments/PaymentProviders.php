<?php

namespace App\Payments;

use App\Models\Company;
use Illuminate\Contracts\Container\Container;

/**
 * The payment providers available on this installation (config/payments.php).
 */
class PaymentProviders
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, PaymentProvider>
     */
    public function all(): array
    {
        $providers = [];

        foreach ((array) config('payments.providers', []) as $class) {
            $provider = $this->container->make($class);

            if ($provider instanceof PaymentProvider) {
                $providers[$provider->key()] = $provider;
            }
        }

        return $providers;
    }

    public function find(?string $key): ?PaymentProvider
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /**
     * The provider the company picked, if it is still available. Null means manual payments only.
     */
    public function forCompany(Company $company): ?PaymentProvider
    {
        return $this->find($company->payment_provider);
    }

    /**
     * Providers offered to the company: available in its country.
     *
     * @return array<string, PaymentProvider>
     */
    public function availableFor(Company $company): array
    {
        return array_filter($this->all(), fn (PaymentProvider $provider) => $provider->isAvailableFor($company));
    }

    /**
     * Providers the company can pick as its online payment provider: available and connected.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(Company $company): array
    {
        return array_values(array_map(
            fn (PaymentProvider $provider) => ['value' => $provider->key(), 'label' => $provider->label()],
            array_filter($this->availableFor($company), fn (PaymentProvider $provider) => $provider->isConnected($company)),
        ));
    }

    /**
     * The company's provider when it can take a payment right now (picked, available, connected).
     */
    public function readyFor(Company $company): ?PaymentProvider
    {
        $provider = $this->forCompany($company);

        return $provider !== null && $provider->isAvailableFor($company) && $provider->isConnected($company) ? $provider : null;
    }
}
