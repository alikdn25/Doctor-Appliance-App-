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
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_values(array_map(
            fn (PaymentProvider $provider) => ['value' => $provider->key(), 'label' => $provider->label()],
            $this->all(),
        ));
    }
}
