<?php

namespace App\Sms;

use InvalidArgumentException;

/**
 * The SMS providers the platform supports (config/sms.php). New numbers are taken at the default provider; a company
 * that already has a number keeps using the provider of that number, so switching the default never cuts anyone off.
 */
class SmsProviders
{
    /** @var array<string, SmsProvider> */
    private array $resolved = [];

    public function default(): SmsProvider
    {
        return $this->get((string) config('sms.provider'));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, (array) config('sms.providers'));
    }

    public function get(string $key): SmsProvider
    {
        $class = config("sms.providers.{$key}");

        if (! is_string($class)) {
            throw new InvalidArgumentException("Unknown SMS provider [{$key}].");
        }

        return $this->resolved[$key] ??= app($class);
    }
}
